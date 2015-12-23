<?php
/**
 * AcceptMiddleware.php
 *
 * @author: chazer
 * @created: 16.12.15 19:26
 */

namespace GuzzleHttp;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/*
 * An accept middleware for Guzzle HTTP client.
 * Based on the code of https://djangosnippets.org/snippets/2263/
 */

/**
 * Class AcceptMiddleware
 *
 * @package GuzzleHttp
 */
class AcceptMiddleware
{
    /** @var callable */
    private $nextHandler;

    public static $defaultSettings = [
        // TODO: Add options
    ];

    /**
     * @param callable $nextHandler Next handler to invoke.
     */
    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    /**
     * The next part is a workaround, to avoid the problem, webkit browsers
     * generate, by putting application/xml as the first item in theire
     * accept headers
     *
     * The algorithm changes the order of the best quality fields, if xml appears
     * to be the first entry of best quality and eigther an xhtml or html emtry is,
     * found with also best quality, to xml beeing the last entry of best quality.
     *
     * If only an xhtml entry is found in bestq, but the request contains an html
     * entry with lower rating, it rearranges the html entry to be directly
     * in front of xml.
     *
     * @param float $bestq
     * @param array $result
     * @return array
     */
    protected function webkit_workaround($bestq, $result)
    {
        if ($result[0][0] === "application/xml") {
            $bestresult = [];
            $length = 0;
            $hashtml = False;
            $hasxhtml = False;
            $idxhtml = null;

            $i = 0;
            foreach ($result as $mediatype) {
                if ($mediatype[2] === $bestq) {
                    $bestresult[] = $mediatype;
                    $length = $length + 1;
                    if (!$hasxhtml and strtolower($mediatype[0]) === "application/xhtml+xml")
                        $hasxhtml = True;
                    if (!$hashtml and strtolower($mediatype[0]) === "text/html")
                        $hashtml = True;
                }
                if (strtolower($mediatype[0]) === "text/html")
                    $idxhtml = $i;
                $i = $i + 1;
            }

            if (($hashtml or $hasxhtml) and $length > 1) {
                $newresult = array_slice($bestresult, 1);

                if (!$hashtml and $idxhtml) {
                    $htmltype = array_splice($result, $idxhtml, 1)[0];
                    $htmltype = [$htmltype[0], $htmltype[1], $bestq];
                    $newresult[] = $htmltype;
                }

                $newresult[] = $bestresult[0];
                $newresult = array_merge($newresult, array_slice($result, $length));

                $result = $newresult;
            }
        }
        return $result;
    }

    /**
     * Parse the Accept header *accept*, returning a list with pairs of
     * (media_type, q_value), ordered by q values.
     *
     * @param string $accept
     * @return array
     */
    protected function parse_accept_header($accept)
    {
        $bestq = 0.0;
        $result = [];
        foreach (explode(",", $accept) as $media_range) {
            $parts = explode(';', $media_range);
            $media_type = trim(array_shift($parts));
            $media_params = [];
            $q = 1.0;
            foreach ($parts as $part) {
                list($key, $value) = explode('=', ltrim($part), 2);
                if ($key === "q") {
                    $q = floatval($value);
                } else {
                    $media_params[] = [$key => $value];
                }
            }
            if ($q > $bestq) {
                $bestq = $q;
            }
            $result[] = [$media_type, array_values($media_params), $q];
        }
        $this->usortStable($result, function ($x, $y) {
            return -($x[2] > $y[2] ? 1 : ($x[2] < $y[2] ? -1 : 0));
        });

        $result = $this->webkit_workaround($bestq, $result);

        return $result;
    }


    protected function usortStable(array &$array, $cmp_function)
    {
        $temp = [];
        $i = 0;
        foreach ($array as $key => $value) {
            $temp[$key] = [$i++, $value];
        }
        $success = usort($temp, function ($a, $b) use ($cmp_function) {
            $c = call_user_func($cmp_function, $a[1], $b[1]);
            return ($c !== 0) ? $c : ($a[0] < $b[0] ? -1 : 1);
        });
        $array = array_map(function ($a) {
            return $a[1];
        }, $temp);
        return $success;
    }

    protected function parse_header($string)
    {
        $result = [];
        foreach (explode(",", $string) as $media_range) {
            $parts = explode(';', $media_range);
            $media_type = array_shift($parts);
            $media_params = [];
            foreach ($parts as $part) {
                list($key, $value) = explode('=', ltrim($part), 2);
                $media_params[] = [$key => $value];
            }
            $result[] = [$media_type, array_values($media_params)];
        }
        return $result;
    }

    protected function checkContentType(RequestInterface $request, $options, ResponseInterface $response)
    {
        $accept = $request->getHeader('Accept');

        $acceptValues = [];
        foreach ($accept as $string) {
            foreach ($this->parse_accept_header($string) as $rule) {
                list($type, ,) = $rule;
                $acceptValues[strtolower($type)] = $rule;
            }
        }

        $ct = $response->getHeader('Content-Type');
        if (isset($ct[0])) {
            $ct = $this->parse_header($ct[0]);
            list($type, $params) = $ct[0];
            if (isset($acceptValues[strtolower($type)])) {
                return $response;
            }
        }
        throw new \Exception('Not allowed content type: ' . $ct[0]);
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     *
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        $fn = $this->nextHandler;

        $accept = $request->getHeader('Accept');

        if (empty($accept)) {
            return $fn($request, $options);
        }

        return $fn($request, $options)
            ->then(function (ResponseInterface $response) use ($request, $options) {
                return $this->checkContentType($request, $options, $response);
            });
    }
}
