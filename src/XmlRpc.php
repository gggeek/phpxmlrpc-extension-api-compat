<?php

/**
 * (Try to) implement the same API of the PHP native XMLRPC extension, so that
 * projects relying on it can be ported to php installs where the extension is
 * missing.
 *
 * @author Gaetano Giunta
 * @copyright (c) 2020-2021 G. Giunta
 * @license code licensed under the BSD License: see license.txt
 *
 * Known differences from the observed behaviour of the PHP extension:
 * Definitely to fix:
 * - the $output_options argument in xmlrpc_encode_request() is only partially supported
 * - two functions are not implemented yet - they exist but do nothing: xmlrpc_parse_method_descriptions and
 *   xmlrpc_server_register_introspection_callback
 * - php arrays indexed with integer keys starting above zero or whose keys are
 *   not in a strict sequence will be converted into xmlrpc structs, not arrays
 * - error codes and error strings in Fault responses generated by the Server for invalid calls are different
 * Possibly to fix:
 * - xmlrpc_server_create() returns an object instead of a resource
 * - a single NULL value passed to xmlrpc_encode_request(null, $val) will be decoded as '', not NULL
 *   (the extension generates an invalid xmlrpc response in this case)
 * - php arrays indexed with mixed string/integer keys will preserve the integer keys in the generated structs
 * - server method `system.getCapabilities` returns different results
 * - server method `system.describeMethods` returns partial data compared to what can be added via
 *   xmlrpc_parse_method_descriptions and xmlrpc_server_register_introspection_callback - but the native extension
 *   version of the same method is buggy anyway: it does not list any method's definition...
 * Won't fix:
 * - differences in the generated xml
 *   - the native extension always encodes double values using 13 decimal digits (or 6, depending on version), and pads
 *     with zeros. We use 13 decimal digits and do not pad. Eg:
 *     value 1.1 is encoded as <double>1.1</double> instead of <double>1.1000000000000</double>
 *   - the native extension encodes chars "<", ">" and "&" using numeric entities. We use xml named entities, eg:
 *     value '&' is encoded as <string>&amp;</string> instead of <string>&#38;</string>.
 *     Also, we do encode the single quote character "'" as &quot;, whereas the extension does not encode it at all
 *   - when encoding base64 values, we don't add encoded newline characters (&#10;)
 *   - some versions of the extension have a bug encoding Latin-1 characters with code points between 200 and 209
 *     (see https://bugs.php.net/bug.php?id=80559). We do not
 *   - calling `xmlrpc_encode_request($methodName, $utf8text, options(array('encoding' => 'UTF-8')))` is buggy with
 *     the extension (wrong character entities are generated). It works with us
 * - differences in parsing xml
 *   - some invalid requests / responses will not be accepted that the native extension allows through:
 *     - missing '<param>' inside '<params>'
 *       eg. <methodCall><methodName>hey</methodName><params><value><string>hey</string></value></params></methodCall>
 *     - missing '<params>' altogether inside '<methodCall>'
 *     - missing '<value>' inside '<param>'
 * - differences in the API:
 *   - arrays which look like an xmlrpc fault and are passed to xmlrpc_encode_request() will be encoded
 *     as structs (the extension generates an invalid xmlrpc request in this case)
 *   - sending a request for `system.methodHelp` and `system.methodSignature` for a method registered with a Server
 *     without adding any related introspection data results in an invalid response with the native extension; it does
 *     not with our code
 *   - calling `xmlrpc_server_add_introspection_data` with method signatures makes the server validate the number
 *     and type of incoming parameters in later calls to `xmlrpc_server_call_method`, relieving the developer from
 *     having to implement the same checks manually in her php functions
 *   - marking input parameters as optional in the data passed to calls to `xmlrpc_server_add_introspection_data` and
 *     `xmlrpc_server_register_introspection_callback` will change the number of method signatures displayed by the
 *     server in responses to calls to `system.methodSignature`.
 *     Eg. passing in one signature with one optional param will result in two signatures displayed, one with no params
 *     and one with one param
 */

namespace PhpXmlRpc\Polyfill\XmlRpc;

use PhpXmlRpc\Encoder;
use PhpXmlRpc\PhpXmlRpc;
use PhpXmlRpc\Request;
use PhpXmlRpc\Response;
use PhpXmlRpc\Server as BaseServer;
use PhpXmlRpc\Value;

final class XmlRpc
{
    public static $xmlpc_double_precision = 13;

    /**
     * Decode the xml generated by xmlrpc_encode() into native php types
     * @param string $xml
     * @param string $encoding target charset encoding for the returned data. Note: when the xml string contains any
     *                         characters which can not be represented in the target encoding, the returned data will
     *                         be in utf8
     * @return mixed
     * @bug known case not to work atm: '<params><param><value><string>Hello</string></value></param><param><value><string>Dolly</string></value></param></params>'
     */
    public static function xmlrpc_decode($xml, $encoding = "iso-8859-1")
    {
        $encoder = new Encoder();
        // Strip out unnecessary xml in case we're deserializing a single param.
        // In case of a complete response, we do not have to strip anything.
        // Please note that the test below has LARGE space for improvement (eg. it does not work for an xml chunk
        // with 2 or more params. Also, it might trip on xml comments...)
        $xmlDeclRegex = '<\?xml\s+version\s*=\s*["\']1\.[0-9]+["\'](?:\s+encoding=["\'][A-Za-z](?:[A-Za-z0-9._]|-)*["\'])?\s*\?>';
        if (preg_match('/^(' . $xmlDeclRegex . ')?\s*<params>/', $xml)) {
            $xml = preg_replace(array('!\s*<params>\s*<param>\s*!', '!\s*</param>\s*</params>\s*$!'), array('', ''), $xml);
        } elseif (preg_match('/^(' . $xmlDeclRegex . ')?\s*<param>/', $xml)) {
            $xml = preg_replace(array('!\s*<param>\s*!', '!\s*</param>\s*$!'), array('', ''), $xml);
        } elseif (preg_match('#^(' . $xmlDeclRegex . ')?\s*(<(?:int|i4|boolean|string|double|dateTime.iso8601|struct|array)>.+</(?:int|i4|boolean|string|double|dateTime.iso8601|struct|array)>)$#', $xml, $matches)) {
            $xml = $matches[1] . '<value>' . $matches[2] . '</value>';
        }

        $defaultEncoding = PhpXmlRpc::$xmlrpc_internalencoding;
        PhpXmlRpc::$xmlrpc_internalencoding = 'UTF-8';

        $options = array('extension_api');
        if (strtoupper($encoding) != 'UTF-8') {
            // NB: always set xmlrpc_internalencoding = 'UTF-8' when setting 'extension_api_encoding'
            $options['extension_api_encoding'] = $encoding;
        }

        $val = $encoder->decodeXml($xml);

        if (!$val) {
            $out = null; // instead of false
        } else {
            if ($val instanceof Response) {
                if ($fc = $val->faultCode()) {
                    $fs = $val->faultString();
                    $out = array('faultCode' => $fc, 'faultString' => self::fromUtf8($encoding, $fs));
                } else {
                    $out = $encoder->decode($val->value(), $options);
                }
            } else {
                $out = $encoder->decode($val, $options);
            }
        }

        PhpXmlRpc::$xmlrpc_internalencoding = $defaultEncoding;

        return $out;
    }

    /**
     * Decode an xmlrpc request (or response) into native PHP types
     * @param string $xml
     * @param string $method (will not be set when decoding responses)
     * @param string $encoding target charset encoding for the returned data. Note: when the xml string contains any
     *                         characters which can not be represented in the target encoding, the returned data will
     *                         be in utf8
     * @return mixed
     *
     * @bug fails for $xml === true, $xml === false, $xml === integer, $xml === float
     */
    public static function xmlrpc_decode_request($xml, &$method, $encoding = "iso-8859-1")
    {
        $encoder = new Encoder();

        $defaultEncoding = PhpXmlRpc::$xmlrpc_internalencoding;
        PhpXmlRpc::$xmlrpc_internalencoding = 'UTF-8';

        $options = array('extension_api');
        if (strtoupper($encoding) != 'UTF-8') {
            // NB: always set xmlrpc_internalencoding = 'UTF-8' when setting 'extension_api_encoding'
            $options['extension_api_encoding'] = $encoding;
        }

        $val = $encoder->decodeXml($xml);
        if (!$val) {
            $out = null; // instead of false
        } else {
            if ($val instanceof Response) {
                if ($fc = $val->faultCode()) {
                    $out = array('faultCode' => $fc, 'faultString' => self::fromUtf8($encoding, $val->faultString()));
                } else {
                    $out = $encoder->decode($val->value(), $options);
                }
            } else if ($val instanceof Request) {
                $method = self::fromUtf8($encoding, $val->method());
                $out = array();
                $pn = $val->getNumParams();
                for ($i = 0; $i < $pn; $i++)
                    $out[] = $encoder->decode($val->getParam($i), $options);
            } else {
                /// @todo copy lib behaviour in this case
                $out = null;
            }
        }

        PhpXmlRpc::$xmlrpc_internalencoding = $defaultEncoding;

        return $out;
    }

    /**
     * Given a PHP val, convert it to xmlrpc code (wrapped up in either params/param elements or a fault element).
     * @param mixed $val
     * @return string
     * @todo test what happens with arrays with faultCode === 0|''|null
     */
    public static function xmlrpc_encode($val)
    {
        $encoder = new Encoder();

        $defaultPrecision = PhpXmlRpc::$xmlpc_double_precision;
        PhpXmlRpc::$xmlpc_double_precision = self::$xmlpc_double_precision;
        $defaultEncoding = PhpXmlRpc::$xmlrpc_internalencoding;
        PhpXmlRpc::$xmlrpc_internalencoding = 'ISO-8859-1';

        $eval = $encoder->encode($val, array('extension_api'));

        if (is_array($val) && isset($val['faultCode'])) {
            $out = "<?xml version=\"1.0\" encoding=\"utf-8\"?" . ">\n<fault>\n " . $eval->serialize('US-ASCII') . "</fault>";
        } else {
            $out = "<?xml version=\"1.0\" encoding=\"utf-8\"?" . ">\n<params>\n<param>\n " . $eval->serialize('US-ASCII') . "</param>\n</params>";
        }

        PhpXmlRpc::$xmlrpc_internalencoding = $defaultEncoding;
        PhpXmlRpc::$xmlpc_double_precision = $defaultPrecision;

        return $out;
    }

    /**
     * Given a method name and array of php values, create an xmlrpc request out
     * of them. If method name === null, create an xmlrpc response instead
     * @param string $method
     * @param array $params
     * @param array $output_options options array. At the moment only partial support for 'encoding' and 'escaping' is
     *                              provided.
     *                              encoding: iso-8859-1, utf-8
     *                              escaping: [markup], [markup,non-print]. non-ascii is treated the same as non-print
     * @return string
     *
     * @todo complete parsing/usage of options: encoding, escaping.
     * @todo might, or not, implement support for options: output_type, verbosity
     */
    public static function xmlrpc_encode_request($method, $params, $output_options = array())
    {
        $encoder = new Encoder();

        $internalEncoding = 'ISO-8859-1';
        $targetEncoding = 'iso-8859-1';
        $targetCharset = 'US-ASCII';

        if (isset($output_options['encoding'])) {
            $targetEncoding = $output_options['encoding'];
            $internalEncoding = $targetEncoding;
        }

        if (isset($output_options['escaping'])) {
            switch(true) {
                /// @todo improve this:
                ///       escaping strategies supported by the native extension can be combined, and are:
                ///         - cdata: wraps text in a cdata section
                ///         - non-print: uses utf8 char entities for chars <32 and >126
                ///         - non-ascii: uses utf8 char entities for chars > 127
                ///         - markup: uses utf8 char entities for & " < >
                ///       If not specified, it defaults to markup | non-ascii | non-print
                ///       Otoh the default serialization strategy from phpxmlrpc is to
                ///         - always escape & " < > and '
                ///         - when going ut8 -> utf8, touch nothing else
                ///         - when going iso-8859-1 -> ascii, convert chars <32 and 160-255 (but not 127-159)
                ///         - when going utf8 -> ascii, convert chars <32 and >= 128 (but not 127)
                ///         - never wrap the text in a cdata section
                ///        We should:
                ///        1. log a warning if being passed options which do not make sense, eg.
                ///           - cdata along with any other option
                ///           - any strategy, apart cdata, missing markup (as we always escape markup)
                ///           - an empty array (same)
                ///        2. support cdata escaping (done correctly)
                ///        3. support different escaping for non-print and non-ascii
                case is_array($output_options['escaping']) && !in_array('non-print', $output_options['escaping']) && !in_array('non-ascii', $output_options['escaping']):
                case $output_options['escaping'] == 'markup':
                case $output_options['escaping'] == 'cdata':
                    $targetCharset = $targetEncoding;
            }
        }

        $output_options = array('extension_api');

        $defaultPrecision = PhpXmlRpc::$xmlpc_double_precision;
        PhpXmlRpc::$xmlpc_double_precision = self::$xmlpc_double_precision;
        $defaultEncoding = PhpXmlRpc::$xmlrpc_internalencoding;
        PhpXmlRpc::$xmlrpc_internalencoding = $internalEncoding;

        if ($method !== null) {
            // mimic EPI behaviour: if ($val === NULL) then send NO parameters
            if (!is_array($params)) {
                if ($params === NULL) {
                    $params = array();
                } else {
                    $params = array($params);
                }
            } else {
/// @todo fix corner cases
                // if given a 'hash' array, encode it as a single param
                $i = 0;
                $ok = true;
                foreach ($params as $key => $value)
                    if ($key !== $i) {
                        $ok = false;
                        break;
                    } else
                        $i++;
                if (!$ok) {
                    $params = array($params);
                }
            }

            $values = array();
            foreach ($params as $key => $value) {
                $values[] = $encoder->encode($value, $output_options);
            }

            // create request
            $req = new Request($method, $values);
            $out = preg_replace('!^<\\?xml version="1\\.0" encoding="'.$targetCharset.'" \\?>!', "<?xml version=\"1.0\" encoding=\"$targetEncoding\"?>", $req->serialize($targetCharset));
        } else {
            // create response
            if (is_array($params) && self::xmlrpc_is_fault($params))
                $resp = new Response(0, (integer)$params['faultCode'], (string)$params['faultString']);
            else
                $resp = new Response($encoder->encode($params, $output_options));
            $out = "<?xml version=\"1.0\" encoding=\"$targetEncoding\"?" . ">\n" . $resp->serialize($targetCharset);
        }

        PhpXmlRpc::$xmlrpc_internalencoding = $defaultEncoding;
        PhpXmlRpc::$xmlpc_double_precision = $defaultPrecision;

        return $out;
    }

    /**
     * Given a php value, return its corresponding xmlrpc type
     * @param mixed $value
     * @return string
     *
     * @bug fails compatibility for array('2' => true, false)
     * @bug fails compatibility for array(true, 'world')
     */
    public static function xmlrpc_get_type($value)
    {
        switch (strtolower(gettype($value))) {
            case 'string':
                return Value::$xmlrpcString;
            case 'integer':
            case 'resource':
                return Value::$xmlrpcInt;
            case 'double':
                return Value::$xmlrpcDouble;
            case 'boolean':
                return Value::$xmlrpcBoolean;
            case 'array':
                $i = 0;
                $ok = true;
                foreach ($value as $key => $valueue)
                    if ($key !== $i) {
                        $ok = false;
                        break;
                    } else
                        $i++;

                return $ok ? Value::$xmlrpcArray : Value::$xmlrpcStruct;
            case 'object':
                if ($value instanceof Value) {
                    $type = $value->scalarTyp();
                    return str_replace('dateTime.iso8601', 'datetime', $type);
                } elseif ($value instanceof \stdClass && isset($value->xmlrpc_type)) {
                    switch($value->xmlrpc_type) {
                        case 'datetime':
                        case 'base64':
                            return $value->xmlrpc_type;
                        default:
                            return 'none';

                    }
                }
                return Value::$xmlrpcStruct;
            case 'null':
                return Value::$xmlrpcBase64; // go figure why...
        }
    }

    /**
     * Checks if a given php array corresponds to an xmlrpc fault response
     * @param array $arg
     * @return boolean
     */
    public static function xmlrpc_is_fault($arg)
    {
        return is_array($arg) && array_key_exists('faultCode', $arg) && array_key_exists('faultString', $arg);
    }

    /**
     * @param string $xml
     * @return array
     */
    public static function xmlrpc_parse_method_descriptions($xml)
    {
        return Server::parse_method_descriptions($xml);
    }

    /** Server side ***************************************************************/

    /**
     * @param Server $server
     * @param array $desc
     * @return int
     */
    public static function xmlrpc_server_add_introspection_data($server, $desc)
    {
        if ($server instanceof Server) {
            return $server->add_introspection_data($desc);
        }
        return 0;
    }

    /**
     * Parses XML request and calls corresponding method
     * @param Server $server
     * @param string $xml
     * @param mixed $user_data
     * @param array $output_options
     * @return string
     */
    public static function xmlrpc_server_call_method($server, $xml, $user_data, $output_options = array())
    {
        $server->user_data = $user_data;
        return $server->service($xml, true);
    }

    /**
     * Create a new xmlrpc server instance
     * @return Server
     */
    public static function xmlrpc_server_create()
    {
        $s = new Server();
        $s->functions_parameters_type = 'epivals';
        $s->compress_response = false; // since we will not be outputting any http headers to go with it
        return $s;
    }

    /**
     * This function actually does nothing, but it is kept for compatibility.
     * To destroy a server object, just unset() it, or send it out of scope...
     * @param Server $server
     * @return integer
     */
    public static function xmlrpc_server_destroy($server)
    {
        if ($server instanceof Server)
            return 1;
        return 0;
    }

    /**
     * @param Server $server
     * @param string $function
     * @return bool
     */
    public static function xmlrpc_server_register_introspection_callback($server, $function)
    {
        if ($server instanceof Server) {
            return $server->register_introspection_callback($function);
        }
        return false;
    }

    /**
     * Add a php function as xmlrpc method handler to an existing server.
     * PHP function sig: f(string $methodname, array $params, mixed $extra_data)
     * @param Server $server
     * @param string $method_name
     * @param string $function
     * @return boolean true on success or false
     */
    public static function xmlrpc_server_register_method($server, $method_name, $function)
    {
        if ($server instanceof BaseServer) {
            $server->addToMap($method_name, $function);
            return true;
        }
        return false;
    }

    /**
     * Set string $val to a known xmlrpc type (base64 or datetime only), for serializing it later
     * (NB: this will turn the string into an object!).
     * @param string $val
     * @param string $type
     * @return boolean false if conversion did not take place
     */
    public static function xmlrpc_set_type(&$val, $type)
    {
        if (is_string($val)) {
            if ($type == 'base64') {
                $value = array(
                    'scalar' => $val,
                    'xmlrpc_type' => 'base64'
                );
                $val = (object)$value;
            } elseif ($type == 'datetime') {
                if (preg_match('/([0-9]{4}[0-1][0-9][0-3][0-9])T([0-5][0-9]):([0-5][0-9]):([0-5][0-9])/', $val)) {
                    // add 3 object members to make it more compatible to user code
                    $value = array(
                        'scalar' => $val,
                        'xmlrpc_type' => 'datetime',
                        'timestamp' => \PhpXmlRpc\Helper\Date::iso8601Decode($val)
                    );
                    $val = (object)$value;
                } else {
                    return false;
                }
            } else {
                // @todo EPI will NOT raise a warning for good type names, eg. 'boolean', etc...
                trigger_error("invalid type '$type' passed to xmlrpc_set_type()");
                return false;
            }
            return true;
        } else {
            return false;
        }
    }

    protected static function fromUtf8($to, $str)
    {
        if (strtoupper($to) != 'UTF-8') {
            /// @todo support mbstring as an alternative, as well as plain utf8_decode if none are available and target is latin-1
            $dstr = @iconv('UTF-8', $to, $str);
            if ($dstr !== false) {
                return $dstr;
            }
        }
        return $str;
    }
}
