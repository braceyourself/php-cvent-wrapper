<?php
class php_cvent_wrapper {

  private $production_wsdl = 'https://api.cvent.com/soap/V200611.ASMX?WSDL';
  private $sandbox_wsdl = 'https://sandbox-api.cvent.com/soap/V200611.ASMX?WSDL';
  private $wsdl = '';
  private $SoapClient = NULL;
  private $SoapClientOptions = array(
    'exceptions' => TRUE,
    'trace' => TRUE,
  );

  private $CventSessionHeader = NULL;
  private $ServerURL = NULL;

  public function __construct($sandbox = FALSE) {
    $this->wsdl = ($sandbox) ? $this->sandbox_wsdl : $this->production_wsdl;
  }

  private function _call($method, $params) {
    try {
      $url = empty($this->ServerURL) ? $this->wsdl : $this->ServerURL;
      $this->SoapClient = new SoapClient($url, $this->SoapClientOptions);
      if(!empty($this->CventSessionHeader)) {
        $header_body = array('CventSessionValue' => $this->CventSessionHeader);
        $header = new SoapHeader('http://api.cvent.com/2006-11', 'CventSessionHeader', $header_body);
        $this->SoapClient->__setSoapHeaders($header);
      }
      return $this->SoapClient->__soapCall($method, $params);
    } catch (\SoapFault $fault) {
      $message = 'Error with Cvent API. Exception occurred.' . PHP_EOL;
      $message .= 'faultcode: ' . $fault->faultcode . PHP_EOL;
      $message .= 'Code: ' . $fault->getCode() . PHP_EOL;
      $message .= 'Message: ' . $fault->getMessage() . PHP_EOL;
      $message .= 'Sent Headers: ' . PHP_EOL . $this->SoapClient->__getLastRequestHeaders();
      $message .= 'Sent Request: ' . PHP_EOL . $this->SoapClient->__getLastRequest();
      throw new Exception($message);
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
   * @param string $ObjectType e.g. User, Event, etc.
   * @param array $Filter array, but make sure it's an array of objects so that it plays nicely with SOAP, e.g.
   * @param string $SearchType can be either 'AndSearch' or 'OrSearch' (default is 'AndSearch')
   * @return array
   * @link https://developers.cvent.com/documentation/soap-api/call-definitions/search-and-retrieve/search/
   * @link https://developers.cvent.com/documentation/soap-api/object-definitions/cvsearchobject/
   */
  public function search($ObjectType, $Filter = array(), $SearchType = 'AndSearch') {
    $search_result = $this->_call('Search', array(
      'Search' => array(
        'ObjectType' => $ObjectType,
        'CvSearchObject' => (object)array(
          'SearchType' => $SearchType,
          'Filter' => $Filter,
        )
      )
    ));

    // Normalize the output. The API would otherwise return a String for single
    // results and array for multiple. This way we always return an array.
    $results = isset($search_result->SearchResult->Id) ? $search_result->SearchResult->Id : array();
    return is_array($results) ? $results : array($results);
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
   * @param string $ObjectType
   * @param string|array $Ids can be a single-record Id string or array of several Id values
   * @param array $Fields Id will always be included, it's also the default
   * @return array associative array of results, with record Ids for keys
   */
  public function retrieve($ObjectType, $Ids, $Fields = array('Id')) {

    // we always want to grab the Id because we'll be building an associative
    // array for the result set
    if(!in_array('Id', $Fields)) {
      $Fields[] = 'Id';
    }

    $retrieve_result = $this->_call('Retrieve', array(
      'Retrieve' => array(
        'ObjectType' => $ObjectType,
        'Ids' => $Ids
      )
    ));

    // normalize the API result as an array so we can process one or several
    // results the same way
    $results = isset($retrieve_result->RetrieveResult->CvObject) ? $retrieve_result->RetrieveResult->CvObject : array();
    $results = is_array($results) ? $results : array($results);

    // build up the return assoc. array based on the fields we want back
    $return = array();
    foreach($results as $result_single) {
      $return[$result_single->Id] = array();
      foreach($Fields as $field) {
        $return[$result_single->Id][$field] = $result_single->$field;
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
   * @see search()
   * @see retrieve()
   */
  public function search_and_retrieve($ObjectType, $Filter = array(), $Fields, $SearchType = 'AndSearch') {
    return $this->retrieve(
      $ObjectType,
      $this->search($ObjectType, $Filter, $SearchType),
      $Fields
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
  public function login($account_number, $username, $password) {
    $result = $this->_call('Login', array(
      'Login' => array(
        'AccountNumber' => $account_number,
        'UserName' => $username,
        'Password' => $password
      )
    ));

    if(
      isset($result->LoginResult->LoginSuccess)
      && isset($result->LoginResult->CventSessionHeader)
      && $result->LoginResult->LoginSuccess
    ) {
      $this->CventSessionHeader = $result->LoginResult->CventSessionHeader;
      $this->ServerURL = $result->LoginResult->ServerURL . '?WSDL';
      return TRUE;
    }
    elseif(isset($result->LoginResult->ErrorMessage)) {
      $message = 'Error authenticating with Cvent. An error message was found.' . PHP_EOL;
      $message .= 'Error Message: ' . $result->LoginResult->ErrorMessage . PHP_EOL;
    }
    else {
      $message = 'Error authenticating with Cvent. No error message was received.' . PHP_EOL;
    }
    $message .= 'Sent Headers: ' . PHP_EOL . $this->SoapClient->__getLastRequestHeaders();
    $message .= 'Sent Request: ' . PHP_EOL . $this->SoapClient->__getLastRequest();
    throw new Exception($message);
  }

}
