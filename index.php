<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__.'/vendor/autoload.php';

use Silex\Provider\ValidatorServiceProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

$app = new Silex\Application();
// Please set to false in a production environment
$app['debug'] = true;

$app->register(new ValidatorServiceProvider());

$app->get('/', function() {
    // @todo Turn into template.
    return <<<'HTML'
<html>
    <head>
        <meta charset="utf-8">
        <script src="http://code.jquery.com/jquery-3.3.1.min.js"></script>
    </head>
    <body>
        <!-- Table 1 -->
        <table id='table1'>
            <thead>
                <tr>
                    <th>Рубрика</th>
                    <th>Количество вакансий</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
        <!-- Table 2 -->
        <table id='table2'>
            <thead>
                <tr>
                    <th>Слово</th>
                    <th>Количество упоминаний</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </body>
    <script>
        jQuery(function() {
            /**
             * @todo Move to separate js-file.
             */

            function report($e) {
                console.log($e);
                //Rollbar.error($e);
            };

            function ajaxUrl(url, query={}, settings={}) {
                settings['method'] = settings['method'] || 'GET';
                // @link https://stackoverflow.com/a/29034314
                settings['type'] = settings['method'];
                // Otherwise it could fail by timeout.
                settings['timeout'] = settings['timeout'] || 120000;
                // @link https://stackoverflow.com/a/10024557
                settings['tryCount'] = settings['tryCount'] || 0;
                settings['retryLimit'] = settings['retryLimit'] || 10;
                if (query) {
                    url += ('?' + jQuery.map(query, function(value, key) { return key+'='+value; }).join('&'));
                }
                return wrapAjaxPromise(jQuery.ajax(url, settings));
            }

            /**
             * @link https://gist.github.com/shama/24654eab9f6c1ed055b6ebfe776eb10d
             */
            function wrapAjaxPromise(jqPromise) {
                return new Promise(function(resolve, reject) {
                    return jqPromise
                        //.then((data, textStatus, jqXHR) => resolve({"data": data, "textStatus": textStatus, "jqXHR": jqXHR}))
                        .then(function(jqXHR) { return resolve(jqXHR); })
                        //.fail((jqXHR, textStatus, errorThrown) => reject({"jqXHR": jqXHR, "textStatus": textStatus, "errorThrown": errorThrown}))
                        .fail(function(jqXHR, textStatus, errorThrown) {
                            var self = this;
                            // Handle cases when hosting responds with 503 from time to time.
                            // @link https://stackoverflow.com/a/10024557
                            if (textStatus == 'timeout' || textStatus == 'error') {
                                self.tryCount++;
                                if (self.tryCount <= self.retryLimit) {
                                    // Try again.
                                    return new Promise(function(resolve) {
                                        setTimeout(resolve, 5000);
                                    })
                                    .then(function() {
                                        return resolve(wrapAjaxPromise(jQuery.ajax(self)));
                                    });
                                }
                            }
                            // Let reject triage the error: whether to throw or to continue.
                            reject({
                                "jqXHR": jqXHR,
                                "textStatus": textStatus,
                                "errorThrown": errorThrown
                            });
                            // Return passed value - to continue the flow.
                            return jqXHR;
                        });
                })
                .catch(report);
            }

            var apiBaseUrl = 'https://api.zp.ru/v1/';

            // Fill in Table 1.
            ajaxUrl('/v1/statistics', {
                'select': 'vacancies',
                'groupBy': 'rubric',
                'orderBy': 'count',
                'orderDir': 'desc'
            }).then(function(data) {
                console.log(data);
                jQuery.each(data, function(index, value) {
                    var tableTbody = jQuery('#table1 tbody');
                    tableTbody.html(tableTbody.html() + '<tr><td><a href="'+apiBaseUrl+'rubrics/'+value['id']+'">'+value['title']+'</a></td><td>'+value['count']+'</td></tr>');
                });
            });

            // Fill in Table 2.
            ajaxUrl('/v1/statistics', {
                'select': 'vacancies',
                'groupBy': 'word',
                'orderBy': 'count',
                'orderDir': 'desc'
            }).then(function(data) {
                console.log(data);
                jQuery.each(data, function(index, value) {
                    var tableTbody = jQuery('#table2 tbody');
                    tableTbody.html(tableTbody.html() + '<tr><td>'+value['word']+'</td><td>'+value['count']+'</td></tr>');
                });
            });
        });
    </script>
</html>
HTML;
});

$app->get('/v1/statistics', function (Request $request) use ($app) {
    // Validation.
    // @link https://medium.com/@peter.lafferty/http-request-validation-with-silex-9ebd7fb37f37
    $getQuery = $request->query->all();
    $errors =  $app['validator']->validate(
        $getQuery,
        new Assert\Collection([
            'select' => [
                new Assert\NotBlank(),
                new Assert\Regex("/vacancies/"),
            ],
            'groupBy' => [
                new Assert\NotBlank(),
                new Assert\Regex("/rubric|word/"),
            ],
            'orderBy' => [
                new Assert\NotBlank(),
                new Assert\Regex("/count/"),
            ],
            'orderDir' => [
                new Assert\NotBlank(),
                new Assert\Regex("/asc|desc/"),
            ],
        ])
    );
    if (count($errors) > 0) {
        $messages = [];
        foreach ($errors as $error) {
            $messages[] = $error->getPropertyPath() . ' ' . $error->getMessage();
        }
        return new JsonResponse($messages, 400);
    }

    // @todo Refactor: Strategy pattern.
    switch (true) {
        case ($getQuery['select'] == 'vacancies' && $getQuery['groupBy'] == 'rubric'):
            $strategy = 'rubric';
            break;
        case ($getQuery['select'] == 'vacancies' && $getQuery['groupBy'] == 'word'):
            $strategy = 'word';
            break;
        default:
            throw new \Exception("Can't determine strategy for request: ".print_r($getQuery, true));
    }

    // Request API vacancies.
    $client = new \GuzzleHttp\Client();

    // Loop through all the pages.
    $vacancies = [];
    $offset = 0;
    $limit = 100;
    do {
        $res = $client->get('https://api.zp.ru/v1/vacancies', [
            'query' => [
                // Added only.
                'is_new_only' => 1,
                // Novosibirsk.
                'geo_id' => '826',
                // Current day.
                'period' => 'today',
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
        $apiResponse = json_decode($res->getBody(), true);

        $vacancies = from(
                from($apiResponse['vacancies'])
                    // Key by id to make union possible.
                    ->toDictionary(function($v, $k) { return $v['id']; })
            )->union($vacancies, function($v, $k) { return $k; });

        $offset += $limit;
    } while ($apiResponse['metadata']['resultset']['count'] > $offset);

    switch ($strategy) {
        case 'rubric':
            // Flatten rubrics.
            $vacanciesFlattened = from($vacancies)
                ->aggregate(function ($a, $v, $k) {
                    foreach ((array)$v['rubrics'] as $rubric) {
                        $a[] = [
                            'vacancy_id' => $v['id'],
                            'rubric_id' => $rubric['id'],
                            'rubric_title' => $rubric['title'],
                        ];
                    }
                    return $a;
                }, []);

            // Group by rubric.
            $vacanciesGroupped = from($vacanciesFlattened)
                ->groupBy(
                    function($v, $k) { return $v['rubric_id']; },
                    function($v, $k) { return $v; },
                    function($e, $k) { return ['id' => $e[0]['rubric_id'], 'title' => $e[0]['rubric_title'], 'count' => count($e)]; }
                )
                ->toValues()
                ->orderByDir(($getQuery['orderDir'] == 'desc'), function($v, $k) { return $v['count']; })
                ->toValues();

            $strategyResult = $vacanciesGroupped->toArrayDeep();
            break;
        case 'word':
            require_once('vendor/Hkey1/fast-ru-morf/morf.php');
            // Flatten words in title.
            $vacanciesFlattened = from($vacancies)
                ->aggregate(function ($a, $v, $k) {
                    // @link http://subcoder.ru/str_word_count-и-русский-текст/
                    $words = (array)str_word_count($v['header'], 1, 'АаБбВвГгДдЕеЁёЖжЗзИиЙйКкЛлМмНнОоПпРрСсТтУуФфХхЦцЧчШшЩщЪъЫыЬьЭэЮюЯя');
                    foreach ($words as $word) {
                        $word = mb_strtolower($word);
                        $a[] = [
                            'vacancy_id' => $v['id'],
                            'vacancy_title' => $v['header'],
                            'word' => $word,
                            // Take morphology into account.
                            'word_id' => ($wordId = \MorfFindGroup($word)) ? $wordId : $word,
                        ];
                    }
                    return $a;
                }, []);

            // Group by word.
            $vacanciesGroupped = from($vacanciesFlattened)
                ->groupBy(
                    function($v, $k) { return $v['word_id']; },
                    function($v, $k) { return $v; },
                    function($e, $k) { return ['word' => $e[0]['word'], 'count' => count($e)]; }
                )
                ->toValues()
                ->orderByDir(($getQuery['orderDir'] == 'desc'), function($v, $k) { return $v['count']; })
                ->toValues();

            $strategyResult = $vacanciesGroupped->toArrayDeep();
            break;
    }

    return new JsonResponse($strategyResult);
});

$app->run();