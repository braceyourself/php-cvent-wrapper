<?php namespace Braceyourself\Cvent;

use Braceyourself\Cvent\Exceptions\CventAuthorizationFailureException;
use Braceyourself\Cvent\Exceptions\CventAuthorizationLockoutException;
use Braceyourself\Cvent\Exceptions\InvalidObjectNameException;
use Braceyourself\Cvent\Exceptions\InvalidSearchFilterException;
use Braceyourself\Cvent\Support\Filter;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class Cvent
{
    private $production_wsdl = 'https://api.cvent.com/soap/V200611.ASMX?WSDL';
    private $sandbox_wsdl = 'https://sandbox-api.cvent.com/soap/V200611.ASMX?WSDL';
    private $wsdl = '';
    private $client = NULL;
    private $session_header = NULL;
    private $server_url = NULL;
    private $client_options = [
        'exceptions' => TRUE,
        'trace'      => TRUE,
    ];
    public static array $objects = [
        'Guest',
        'Invitee',
        'Registration',
        'EventParameters',
        'EventDetail',
        'EventEmailHistory',
        'Transaction',
        'Travel',
        'Budget',
        'BudgetItem',
        'EventQuestion',
        'TableAssignment',
        'Event',
        'Survey',
        'Contact',
        'Speaker',
        'Session',
        'eMarketing',
        'Administration',
    ];


    public function __construct($sandbox = false)
    {
        $this->wsdl = ($sandbox) ? $this->sandbox_wsdl : $this->production_wsdl;
    }


    public function call($method, $params)
    {
        try {
            $url = empty($this->server_url) ? $this->wsdl : $this->server_url;

            $this->client = new \SoapClient($url, $this->client_options);

            if (!empty($this->session_header)) {

                $header_body = ['CventSessionValue' => $this->session_header];

                $header = new \SoapHeader('http://api.cvent.com/2006-11', 'CventSessionHeader', $header_body);

                $this->client->__setSoapHeaders($header);

            }

            return $this->client->__soapCall($method, $this->parseParams($params));

        } catch (\SoapFault $fault) {
            /** @var \Exception $exception */
            $exception = \Str::of($fault->getMessage())->lower()->studly()
                ->prepend(__NAMESPACE__ . "\Exceptions\\")
                ->append('Exception');

            $message = 'Error with Cvent API. Exception occurred.' . PHP_EOL;
            $message .= 'faultcode: ' . $fault->faultcode . PHP_EOL;
            $message .= 'Code: ' . $fault->getCode() . PHP_EOL;
            $message .= 'Message: ' . $fault->getMessage() . PHP_EOL;


            // if we have Request headers and data, add to the message
            if ($this->client) {
                $message .= 'Sent Headers: ' . PHP_EOL . $this->client->__getLastRequestHeaders();
                $message .= 'Sent Request: ' . PHP_EOL . $this->client->__getLastRequest();
            }

            if ($fault->getMessage() === 'INVALID_SEARCH_FILTER') {
                throw new InvalidSearchFilterException($message, $fault->getCode(), $fault);
            }

            if (class_exists($exception) && in_array(\Throwable::class, class_implements($exception))) {
                throw new $exception($message, $fault->getCode(), $fault);
            }


            throw new \Exception($message);
        }
    }

    /**
     * Search Cvent for an any searchable object, using any searchable fields.
     *
     * Search using any Cvent filters, using an AND or an OR search. Note that not
     * all fields are searchable. Also note that the Cvent API can return a single
     * _or_ or an array of IDs, but this method will normalize this and always
     * give you an array, even if there is only one element in that array. Here's
     * an example to pull out only Users who have the "Administrators" role:
     * active:
     * <code>
     * $php_cvent_wrapper->search(
     *   'User',
     *   array(
     *     (object)array(
     *       'Field' => 'UserRole',
     *       'Operator' => 'Equals',
     *       'Value' => 'Administrators',
     *     )
     *   )
     * );
     * </code>
     *
     * @param string $object_type e.g. User, Event, etc.
     * @param array $filter array, but make sure it's an array of objects so that it plays nicely with SOAP, e.g.
     * @param string $search_type can be either 'AndSearch' or 'OrSearch' (default is 'AndSearch')
     * @return array
     * @throws \Exception
     * @link https://developers.cvent.com/documentation/soap-api/object-definitions/cvsearchobject/
     * @link https://developers.cvent.com/documentation/soap-api/call-definitions/search-and-retrieve/search/
     */
    public function search(string $object_type, $filter = [], $search_type = 'AndSearch')
    {
        $search_result = $this->call('Search', [
            'Search' => [
                'ObjectType'     => $object_type,
                'CvSearchObject' => (object)[
                    'SearchType' => $search_type,
                    'Filter'     => $filter,
                ]
            ]
        ]);

        // Normalize the output. The API would otherwise return a String for single
        // results and array for multiple. This way we always return an array.
        $results = isset($search_result->SearchResult->Id)
            ? $search_result->SearchResult->Id
            : [];

        return Arr::wrap($results);

    }

    /**
     * Retrieve one or several records from any Cvent object.
     *
     * Retrieve is used when you have one or several IDs for Cvent records and
     * would like to have more information about them, e.g. the email address of
     * the user, or the start date of the event. Use this method to isolate only
     * the required fields in the result set (and avoid managing larger than
     * necessary record data arrays). Here's an example of getting more user data
     * from an array of known user Ids:
     * <code>
     * $php_cvent_wrapper->retrieve(
     *   'User',
     *   array(
     *     '7EE3FBC2-006F-4EBD-B4F2-16B4E7E719BE',
     *     '668AZX5C-A1F6-415D-BF41-6903CEF47340',
     *   ),
     *   array(
     *     'Email',
     *     'Id',
     *     'UserType',
     *     'UserRole',
     *   )
     * );
     * </code>
     * The result set is always an associative array with record Ids for keys and
     * each nested array using the field name for the key.
     * <code>
     * array(2) {
     *   ["7EE3FBC2-006F-4EBD-B4F2-16B4E7E719BE"]=>
     *   array(4) {
     *     ["Email"]=>
     *     string(25) "SomeGuy@gmail.com"
     *     ["Id"]=>
     *     string(36) "7EE3FBC2-006F-4EBD-B4F2-16B4E7E719BE"
     *     ["UserType"]=>
     *     string(11) "Application"
     *     ["UserRole"]=>
     *     string(14) "Administrators"
     *   }
     *   ["668AZX5C-A1F6-415D-BF41-6903CEF47340"]=>
     *   array(4) {
     *     ["Email"]=>
     *     string(25) "SomeGal@gmail.com"
     *     ["Id"]=>
     *     string(36) "668AZX5C-A1F6-415D-BF41-6903CEF47340"
     *     ["UserType"]=>
     *     string(11) "Application"
     *     ["UserRole"]=>
     *     string(14) "Administrators"
     *   }
     * }
     * </code>
     *
     * @param string $object_type
     * @param string|array $Ids can be a single-record Id string or array of several Id values
     * @param array $Fields Id will always be included, it's also the default
     * @param bool $always_flat Ensures that the return array is always a "flat"
     *        associative array. Set to false to allow Registration Questions to
     *        provide an additional array of questions and answers within the
     *        primary record data array
     * @return Collection record Ids for keys
     * @throws \Exception
     */
    public function retrieve(string $object_type, $Ids, $Fields = ['Id'], $always_flat = TRUE)
    {
        // we always want to grab the Id because we'll be building an associative
        // array for the result set
        if (!in_array('Id', $Fields)) {
            $Fields[] = 'Id';
        }

        $results = $this->call('Retrieve', [
            'Retrieve' => [
                'ObjectType' => $object_type,
                'Ids'        => $Ids
            ]
        ]);


        // normalize the API result as an array so we can process one or several
        // results the same way
        return $this->normalizeResponse($results->RetrieveResult);

        // build up the return assoc. array based on the fields we want back
        $return = [];
        foreach ($results as $result_single) {
            $return[$result_single->Id] = [];

            // ensure CustomFieldDetail is always an array; it will be for any Cvent
            // object with more than one custom field, but I suspect that if only one
            // custom field is set up then it would be treated differently
            if (isset($result_single->CustomFieldDetail) && !is_array($result_single->CustomFieldDetail)) {
                $result_single->CustomFieldDetail = [$result_single->CustomFieldDetail];
            }

            foreach ($Fields as $field) {
                // if we have an empty field reference, just skip it
                if (empty($field)) continue;

                // If the requested field is not found on the retrieved object, we
                // will just assume it is blank
                $return[$result_single->Id][$field] = '';

                // Check standard fields for a match. Standard fields are params. on the
                // object
                if (isset($result_single->$field) && !empty($result_single->$field)) {
                    $return[$result_single->Id][$field] = $result_single->$field;
                }

                // if still blank, perhaps it's a custom field? We have to check each
                // one for a match because custom fields are placed in their own array
                // on the object, and each element contains information about the custom
                // field (i.e. not just the value)
                if (empty($return[$result_single->Id][$field]) && !empty($result_single->CustomFieldDetail)) {
                    foreach ($result_single->CustomFieldDetail as $cvent_custom_field) {
                        if ($field == $cvent_custom_field->FieldName) {
                            $return[$result_single->Id][$field] = $cvent_custom_field->FieldValue;
                        }
                    }
                }

                // if still blank, perhaps it's the Answers set to a Registration
                // object? These questions and answers are somewhat unorganized and
                // difficult to sort through, but if they are present we will format
                // them both as a large text field that specifies the question and
                // response to each entry (for human consumption) _and_ as an array that
                // can be more easily processed and reported on. Answers with multiple
                // responses will always be joined by a comma (CSV).
                if (
                    empty($return[$result_single->Id][$field])
                    && !empty($result_single->EventSurveyDetail)
                    && $field == 'Answer'
                ) {

                    // set up the array portion of the answer set
                    if (!$always_flat) {
                        $return[$result_single->Id][$field . ' Array'] = [];
                    }

                    foreach ($result_single->EventSurveyDetail as $EventSurveyDetail) {

                        // the actual content of the answer is formatted a bit differently
                        // for single responses vs. multiple.
                        $answer = '';
                        if (!empty($EventSurveyDetail->Answer) && is_array($EventSurveyDetail->Answer)) {
                            foreach ($EventSurveyDetail->Answer as $answer_text) {
                                if (!empty($answer_text->AnswerText)) {
                                    $answer .= $answer_text->AnswerText . ', ';
                                }
                                if (!empty($answer_text->AnswerPart)) {
                                    $answer .= $answer_text->AnswerPart . ': ';
                                    if (!empty($answer_text->AnswerOther)) {
                                        $answer .= $answer_text->AnswerOther;
                                    }
                                }
                            }

                            // drop trailing comma
                            if (substr($answer, -2) == ', ') {
                                $answer = substr($answer, 0, -2);
                            }
                        } elseif (!empty($EventSurveyDetail->Answer->AnswerText)) {
                            $answer = $EventSurveyDetail->Answer->AnswerText;
                        }

                        // build the text block for this
                        $return[$result_single->Id][$field] .= 'Question:' . PHP_EOL
                            . $EventSurveyDetail->QuestionText . PHP_EOL
                            . 'Response:' . PHP_EOL
                            . $answer . PHP_EOL . PHP_EOL;

                        if (!$always_flat) {
                            // build the answer as an array as well
                            $return[$result_single->Id][$field . ' Array'][$EventSurveyDetail->QuestionText] = $answer;
                        }

                    }

                    // drop trailing newline from answers text block
                    $return[$result_single->Id][$field] = trim($return[$result_single->Id][$field]);
                }


            }
        }
        return $return;
    }

    /**
     * Search for records and Retrieve multiple fields' data in a single call.
     *
     * Wrapper around the Search and Retrieve calls, because why would you want to
     * search for something and not pull out some extra information about it?
     * Here's an example to pull a few fields for all future events (i.e. the
     * Start Date is in the future):
     * <code>
     * $php_cvent_wrapper->search_and_retrieve(
     *   'Event',
     *   array(
     *     (object)array(
     *       'Field' => 'EventStartDate',
     *       'Operator' => 'Greater than',
     *       'Value' => date('Y-m-d\TH:m:s'),
     *     )
     *   ),
     *   array(
     *     'EventCode',
     *     'EventTitle',
     *     'Id',
     *   )
     * );
     * </code>
     * @param $ObjectType
     * @param array $Filter
     * @param $Fields
     * @param string $SearchType
     * @param bool $always_flat
     * @return array
     * @throws \Exception
     * @see retrieve()
     * @see search()
     */
    public function search_and_retrieve($ObjectType, $Filter = [], $Fields = [], $SearchType = 'AndSearch', $always_flat = TRUE)
    {
        return $this->retrieve(
            $ObjectType,
            $this->search($ObjectType, $Filter, $SearchType),
            $Fields,
            $always_flat
        );
    }

    /**
     * Login/Authenticate with Cvent
     *
     * @param string $account_number
     * @param string $username
     * @param string $password
     * @return bool
     * @link https://developers.cvent.com/documentation/soap-api/call-definitions/authentication/login/
     */
    public function login($account_number, $username, $password)
    {

        $error_message = '';
        try {
            $result = $this->call('Login', [
                'Login' => [
                    'AccountNumber' => $account_number,
                    'UserName'      => $username,
                    'Password'      => $password
                ]
            ]);
        } catch (\SoapFault $e) {
            $error_message = $e->getMessage();
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
        }

        if (
            isset($result->LoginResult->LoginSuccess)
            && isset($result->LoginResult->CventSessionHeader)
            && $result->LoginResult->LoginSuccess
            && empty($error_message)
        ) {
            $this->session_header = $result->LoginResult->CventSessionHeader;
            $this->server_url = $result->LoginResult->ServerURL . '?WSDL';
            return TRUE;
        } elseif (isset($result->LoginResult->ErrorMessage) && $result->LoginResult->ErrorMessage == 'Access is denied.') {
            throw new CventAuthorizationFailureException('Access is denied. Please check your Account Number, Username, Password and that your request is coming from an approved IP address');
        } elseif (isset($result->LoginResult->ErrorMessage) && $result->LoginResult->ErrorMessage == 'Your account has been locked out. Please contact Customer care or wait for 30 minutes') {
            throw new CventAuthorizationLockoutException('Account Locked');
        } elseif (isset($result->LoginResult->ErrorMessage)) {
            $error_message .= 'Error authenticating with Cvent. An error message was found.' . PHP_EOL;
            $error_message .= 'Error Message: ' . $result->LoginResult->ErrorMessage . PHP_EOL;
        } elseif (empty($error_message)) {
            $error_message .= 'Error authenticating with Cvent. No error message was received.' . PHP_EOL;
        }

        // scrub sensitive account info. from the error message so it doesn't get
        // sent to logging systems
        $scrub = [$account_number, $username, $password];
        $error_message = str_replace($scrub, '[REDACTED]', $error_message);

        throw new \Exception($error_message);
    }

    /**
     * @param string $CvObject array of Cvent Object names
     * @return array
     */
    public function describe_object($CvObject)
    {
        $result = $this->call('DescribeCvObject', [
            'DescribeCvObject' => [
                'ObjectTypes' => [
                    $CvObject
                ]
            ]
        ]);
        return $result->DescribeCvObjectResult->DescribeCvObjectResult;
    }

    /**
     * @param string $CvObject to get fields for
     * @param bool $include_custom Include Custom Fields (optional, default true)
     * @return array normalized list of fields
     */
    public function describe_object_fields($CvObject, $include_custom = TRUE)
    {
        $describe_object = $this->describe_object($CvObject);
        $fields = [];
        foreach ($describe_object->Field as $field) {
            $fields[] = $field->Name;
        }
        if ($include_custom && !empty($describe_object->CustomField)) {
            foreach ($describe_object->CustomField as $field) {
                $fields[] = $field->Name;
            }
        }
        return $fields;
    }

    private function normalizeResponse($data = [])
    {
        $data = collect(json_decode(json_encode($data), true))
            ->mapInto(Collection::class)
            ->get('CvObject');

        if ($data->get('Id') !== null) {
            $data = collect([$data]);
        }

        return $data;

    }

    private function parseParams($params): array
    {
        $key = 'Search.CvSearchObject.Filter';
        $search = Arr::get($params, 'Search', []);

        if (isset($search['CvSearchObject'])) {
            $filters = collect(data_get($params, $key))->map(function ($filter) {
                if ($filter instanceof Filter) {
                    return $filter->toArray();
                }

                return $filter;
            });

            data_set($params, $key, $filters->toArray());
        }


        return $params;
    }


    public static function validateObjectName($name)
    {
        if (!in_array($name, static::$objects)) {
            throw new InvalidObjectNameException("'$name' is not a valid Cvent object");
        }
    }
}