<?php
class Search
{
    const API_SEARCH_MOVIE_URL = 'https://imdb-api.com/en/API/SearchMovie/';
    const API_SEARCH_TITLE_URL = 'https://imdb-api.com/en/API/Title/';
    const API_SEARCH_RATING_URL = 'https://imdb-api.com/en/API/Ratings/';

    const DESCRIPTION_PARAM = 'description';
    const TITLE_PARAM = 'title';
    const ID_PARAM = 'id';
    const YEAR_PARAM = 'year';

    const SEARCH_TYPE_MOVIE = 'movie';
    const SEARCH_TYPE_INFO = 'info';
    const SEARCH_TYPE_RATING = 'rating';

    const CACHE_EXPIRATION_TIME = 60;
    const CACHE_DIR = 'cache';

    protected $apiUrl = self::API_SEARCH_MOVIE_URL;
    protected $searchType = self::SEARCH_TYPE_MOVIE;
    protected $id;
    protected $title;
    protected $year;
    protected $errorMessage;

    private $apiKey = 'k_z2lcome7';

    public function __construct() {
        $this->title = $this->clearData($_GET[self::TITLE_PARAM]);
        if (!empty($_GET[self::YEAR_PARAM])) {
            $this->year = $this->clearData($_GET[self::YEAR_PARAM]);
        }
    }

    public function run()
    {
        if (!empty($_GET[self::TITLE_PARAM])) {
            if ($movieId = $this->sendQuery()) {
                $movieInfo = $this->getMovieInfo();
            }
            if ($movieId && $movieInfo) {
                $this->showSuccessResponse($movieInfo);
            } else {
                $this->showErrorResponse();
            }
        } else {
            $errMsg = "Using correct pattern: /movies?title={expression}[&year={expression}]";
            $error = [
                "expression" => null,
                "results" => null,
                "invalidRequest" => $errMsg
            ];
            echo json_encode($error, JSON_FORCE_OBJECT);
        }
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    public function sendQuery()
    {
        $cachetime = self::CACHE_EXPIRATION_TIME;
        $cacheDir = self::CACHE_DIR;
        if ( ! is_dir($cacheDir)) {
            mkdir($cacheDir);
        }

        $hash = md5($this->apiUrl);
        $file = $cacheDir . '/' . $hash . self::CACHE_DIR;

        $mtime = 0;
        if (file_exists($file)) {
            $mtime = filemtime($file);
        }
        $filetimeLive = $mtime + $cachetime;
        $curTime = time();

        if (time() > $filetimeLive) {
            $curl = curl_init();
            $url = $this->apiUrl .  $this->prepareQueryParams();
            $params = $this->prepareQueryParams();
            curl_setopt_array($curl, [
                        CURLOPT_URL => $url,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_RETURNTRANSFER => True,
                        CURLOPT_HTTPHEADER => array("content-type: application/json"),
                    ]
                );
            $response = curl_exec($curl);

            $curlError = curl_error($curl);
            curl_close($curl);
            if (!$curlError && $response) {
                file_put_contents($file, $response);
            } else {
                $this->errorMessage = 'A technical failure has occurred. Please repeat your request later';
                return false;
            }
        } else {
            $response = file_get_contents($file);
        }

        return $this->analyseResponse($response);
    }

    protected function analyseResponse($response)
    {
        $response = json_decode($response, true);
        $jsonDecodeError = json_last_error();

        if ($jsonDecodeError !== JSON_ERROR_NONE) {
            $this->errorMessage = 'A technical failure has occurred. Please repeat your request later';
            return false;
        } elseif ($response['errorMessage']) {
            $this->errorMessage = $response['errorMessage'];
            return false;
        } elseif ($this->searchType == self::SEARCH_TYPE_INFO) {
            return $response;
        } elseif ($this->searchType == self::SEARCH_TYPE_MOVIE && !empty($response = $this->filterMovies($response['results']))) {
            $this->setId($response);
            return true;
        } elseif ($this->searchType == self::SEARCH_TYPE_RATING){
            return $response['imDb'];
        } else {
            $this->errorMessage = 'No movie with that title';
            $this->sendNotFoundStatus();
        }
    }


    protected function getMovieInfo() {
        $this->searchType = self::SEARCH_TYPE_INFO;
        $this->apiUrl = self::API_SEARCH_TITLE_URL;
        $movieInfo = $this->sendQuery();
        if (is_array($movieInfo)) {
            if (!$movieInfo['imDbRating']) {
                $this->searchType = self::SEARCH_TYPE_RATING;
                $this->apiUrl = self::API_SEARCH_RATING_URL;
                $movieInfo['imDbRating'] = $this->sendQuery();
            }
            $result = [];
            $result['title'] = $movieInfo['originalTitle'] ? $movieInfo['originalTitle'] : $movieInfo['title'];
            $result['year'] = (int) $movieInfo['year'];
            $result['directors'] = $this->prepareInfoData($movieInfo['directors']);
            $result['genres'] = $this->prepareInfoData($movieInfo['genres']);
            $result['imDbRating'] = (float) $movieInfo['imDbRating'];
            return $result;
        } else {
            $this->sendNotFoundStatus();
        }
    }

    protected function sendNotFoundStatus()
    {
        header('HTTP/1.1 404 Not Found');
        echo 'Status 404. Not found. ' . $this->errorMessage;
        exit();
    }

    protected function showErrorResponse()
    {
        $error = [
            'expression' => $this->title,
            'results' => null,
            'error' => $this->errorMessage
        ];
        echo json_encode($error);
    }

    protected function showSuccessResponse($response)
    {
        $response = json_encode($response);
        echo $response;
    }



    protected function prepareInfoData($data)
    {
        $data = explode(', ', $data);

        return $data;
    }


    protected function filterMovies($results)
    {
        $checkedMovies = [];
        $i = 0;
        $j = 0;
        foreach ($results as $value) {
            if ($this->clearData($value[self::TITLE_PARAM]) == $this->clearData($this->title)) {
                $checkedMovies[$j] = $results[$i];
                $checkedMovies[$j][self::DESCRIPTION_PARAM] = $this->getYear($value[self::DESCRIPTION_PARAM]);
                $j++;
            }
            $i++;
        }
        return $checkedMovies;
    }

    protected function prepareQueryParams()
    {
        switch($this->searchType) {
            default:
            case self::SEARCH_TYPE_MOVIE :
                return $this->apiKey . '/' . $this->title;

            case self::SEARCH_TYPE_INFO :
                return $this->apiKey . '/' . $this->id;
            case self::SEARCH_TYPE_RATING :
                return $this->apiKey . '/' . $this->id;
        }
    }

    private function getYear($string)
    {
        if (preg_match('/\d{4}/', $string, $match)) {
            return $match[0];
        }
        return false;
    }

    private function clearData($data)
    {
        return mb_strtolower(trim(strip_tags($data)));
    }

    private function setId($response)
    {
        $count = count($response);
        if ($count == 1) {
            $this->id = $response[0][self::ID_PARAM];
            return;
        } elseif($count > 1 && !empty($this->year)) {
            $yearMatch = false;

            foreach($response as $value) {
                if ($this->year == $value[self::DESCRIPTION_PARAM]) {
                    $yearMatch = true;
                    $this->id = $value[self::ID_PARAM];
                }
            }

            if (!$yearMatch) {
                $this->errorMessage = 'Year not match';
                $this->sendNotFoundStatus();
            }
        } else {
            $this->errorMessage = 'Several matches. Enter a year for clarification. Pattern: /movies?title={expression}[&year={expression}]';
            $this->sendNotFoundStatus();
        }
    }


}

$search = new Search();
$search->run();





