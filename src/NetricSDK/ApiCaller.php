<?php
namespace NetricSDK;

use NetricSDK\EntityCollection\EntityCollection;
use NetricSDK\Entity\EntityFactory;
use NetricSDK\Entity\Entity;
use NetricSDK\Entity\EntityGrouping;

/**
 * Main class used to make REST API calls to a netric server
 */
class ApiCaller implements ApiCallerInterface
{
	/**
	 * The version of API we are calling
	 */
	const API_VERSION = 2;

	/**
	 * The server host such as https://test.netric.com
	 *
	 * @param string
	 */
	private $server = "";

	/**
	 * The application ID that has been approved to access the API
	 *
	 * @param string
	 */
	private $applicationId = "";

	/**
	 * The private key of the application ID used to sign secure requests
	 *
	 * @param string
	 */
	private $applicationKey = "";

	/**
	 * Auth token used for making requsts to protected resources
	 *
	 * @param string
	 */
	private $authToken = "";

	/**
	 * Constructor will setup API connection credentials
	 *
	 * @param string $server The server we are connecting to
	 * @param string $applicationId A unique ID supplied to grant access to the API for a specific application
	 * @param string $applicationKey The private key used to sign all requests
	 */
	public function __construct($server, $applicationId, $applicationKey)
	{
		$this->server = $server;
		$this->applicationId = $applicationId;
		$this->applicationKey = $applicationKey;
	}

	/**
	 * Save an entity to the backend
	 * 
	 * @param Entity $entity Either a new entity (with no id value set) or an existing entity
	 * @return bool true on success, false on failure
	 */
	public function saveEntity(Entity $entity)
	{
		$values = $entity->getValues();
		$objType = $entity->getType();

		$data = array('obj_type' => $objType);

		foreach ($values as $fieldName=>$value) {
			$data[$fieldName] = $value;
		}

		$ret = $this->sendRequest("entity", "save", $data);

		// Now set any values from the server
		foreach ($ret as $fieldName=>$value) {
			$entity->$fieldName = $value;
		}

		return true;
	}

	/**
	 * Delete an entity from the backend
	 * 
	 * @param Entity $entity Either a new entity (with no id value set) or an existing entity
	 * @return bool true on success, false on failure
	 */
	public function deleteEntity(Entity $entity)
	{
		if (!isset($entity->id) || !$entity->getType()) {
			throw new \Exception("Cannot delete an entity that does not yet exist");
		}

		$data = array(
			"obj_type" => $entity->getType(),
			"ids" => $entity->id
		);
		$ret = $this->sendRequest("entity", "remove", $data);
		return (is_array($ret) && (count($ret) > 0)) ? true : false;
	}

	/**
	 * Retrieve an entity by id
	 *
	 * @param string $objType The name of object this entity represents - like 'user'
	 * @param string $id the Unique id of the entity to load
	 * @return Entity the populated entity if found, or null if it does not exist
	 */
	public function getEntity($objType, $id)
	{
		$data = [
			'obj_type'=>$objType, 
			'id'=>$id
		];
		$ret = $this->sendRequest("entity", "get", $data, 'GET');
		if (is_array($ret) && isset($ret['obj_type']) && isset($ret['id'])) {
			return $this->loadEntityFromData($ret);
		} else if (isset($ret['error'])) {
			throw new \RuntimeException("Could not get entity: " . $ret['error']);
		} else {
			return null;
		}
	}

	/**
	 * Retrieve an entity by id
	 *
	 * @param string $objType The name of object this entity represents - like 'user'
	 * @param string $id the Unique id of the entity to load
	 * @param array $namespaceCondtiions Optional namesapce conditions
	 * @return Entity the populated entity if found, or null if it does not exist
	 */
	public function getEntityByUniqueName($objType, $uname, array $namespaceCondtiions = [])
	{
		$data = [
			'obj_type'=>$objType, 
			'uname'=>$uname,
			'uname_conditions' => $namespaceCondtiions
		];
		$ret = $this->sendRequest("entity", "get", $data);
		if (is_array($ret) && isset($ret['obj_type']) && isset($ret['id'])) {
			return $this->loadEntityFromData($ret);
		} else {
			return null;
		}
	}

	/**
	 * Get object definition based on an object type
	 *
     * @param string $objType The object type name
     * @param string $fieldName The field name to get grouping data for
	 * @return \Netric\Models\EntityGrouping[]
	 */
	public function getEntityGroupings($objType, $fieldName)
    {
        if (!$objType || !$fieldName)
            return array();
            
        $params = array("obj_type"=>$objType, "field_name"=>$fieldName);
		$groupsData = $this->sendRequest("entity", "get-groupings", $params, "GET");

        // Initialize heiarachial array of groupings
        if (isset($groupsData['groups'])) {
	        return $this->loadEntityGropingFromData($groupsData['groups']);
        } else {
        	return array();
        }
    }

	/**
	 * Query the backend for entities that match the passed query conditions and set the collection
	 *
	 * @param EntityCollection $collection A collection to query and set entities into
	 * @return int The number of entities retrieved in the current page
	 */
	public function loadCollection(EntityCollection $collection)
	{
		$queryData = array(
			'obj_type' => $collection->getType(),
			'offset' => $collection->getOffset(),
			'limit' => $collection->getLimit(),
		);

		// Add conditions to the query
		$queryData['conditions'] = array();
		$wheres = $collection->getWheres();
		foreach ($wheres as $where) {
			$queryData['conditions'][] = $where->toArray();
		}

		// Add orer by
		$queryData['order_by'] = $collection->getOrderBy();

		// Call the server to get the query results
		$ret = $this->sendRequest("entity-query", "execute", $queryData);

		// Clear entities because we only want the current page loaded into memory
		$collection->clearEntities();
		$collection->setTotalNum($ret['total_num']);
		foreach ($ret['entities'] as $entityData) {
			$entity = $this->loadEntityFromData($entityData);
			$collection->addEntity($entity);
		}
		return $ret['num'];
	}

	/**
	 * Get an authToken from the server
	 */
	public function getAuthToken()
	{
		$url = $this->server . "/api/" . self::API_VERSION . "/authentication/authenticate?";
		$url .= "username=" . urlencode($this->applicationId);
		$url .= "&password=" . urlencode($this->applicationKey);

		$ch = curl_init($url);
		// set to 0 to eliminate header info from response
		curl_setopt($ch, CURLOPT_HEADER, 0); 
		// Returns response data instead of TRUE(1)
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		
		// execute post and get results
		$resp = curl_exec($ch); 
		curl_close($ch);

		// Get data
		$retData = json_decode($resp, true);
		if ($retData['result'] != 'SUCCESS') {
			throw new \Exception("Auth failed: " . $retData['reason']); 
		}

		// Return JSON decoded response
		return $retData['session_token'];
	}

	/**
     * Send a request using the php api for netric
     * 
     * @param string $controller Controller name to call
     * @param string $action The name of the action to call in the selected controller
     * @param array $data Params (assoc) to be sent to the controller
     * @param string $method Can either be GET or POST
     * @return mixed -1 on falure, string resonse on success
     * @throws Exception
     */
    private function sendRequest($controller, $action, $data, $method='POST')
	{
		$url = $this->server . "/api/" . self::API_VERSION . "/$controller/$action";

		if (!$this->authToken) {
			return $this->getAuthTokenThenSendRequest($controller, $action, $data, $method);
		}

		// If the method is GET then we should append query params
		if ($method === 'GET') {
			$urlQueryParams = "";
			foreach ($data as $pname=>$pval) {
				if ($urlQueryParams) {
					$urlQueryParams .= "&";
				}

				if (is_array($pval)) {
					foreach ($pval as $psubval) {
						$urlQueryParams .= $pname . "[]=" . urlencode($psubval);	
					}
				} else {
					$urlQueryParams .= $pname . "=" . urlencode($pval);
				}
			}
			if ($urlQueryParams) {
				$url .= "?" . $urlQueryParams;
			}
		}

		$headers = ['Authentication: ' . $this->authToken];

		$ch = curl_init($url);
		// set to 0 to eliminate header info from response
		curl_setopt($ch, CURLOPT_HEADER, 0); 
		// Returns response data instead of TRUE(1)
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			$headers[] = 'Content-Type: application/json';
		}
		
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		
		// execute post and get results
		$resp = curl_exec($ch); 
		$decodedData = json_decode($resp, true);

		curl_close($ch);

		// Return JSON decoded response
		return $decodedData;
	}

	/**
	 * Function called when we don't have a valid auth token
	 * 
	 * When it finishes it will call sendRequest again
	 *
	 * @param string $controller Controller name to call
     * @param string $action The name of the action to call in the selected controller
     * @param array $data Params (assoc) to be sent to the controller
     * @return mixed -1 on falure, string resonse on success
     */
	private function getAuthTokenThenSendRequest($controller, $action, $data, $method)
	{
		// Call auth to get a token
		$this->authToken = $this->getAuthToken();

		if (!$this->authToken) {
			throw new \Exception("Could not get auth token for some reason");
		}

		return $this->sendRequest($controller, $action, $data, $method);
	}

	/**
	 * Initialze all the properties in an entity from data
	 * 
	 * @param array $data The data to load into the entity
	 * @return Entity An initialized entity from the data
	 */
	private function loadEntityFromData(array $data)
	{
		if (!isset($data['obj_type'])|| !isset($data['id'])) {
			return null;
		}

		$entity = EntityFactory::factory($data['obj_type'], $data['id']);

		foreach ($data as $fieldName=>$fieldValue) {
			// We don't want to set _fval fields since they are not real entity fields
			if (substr($fieldName, -5, 5) != '_fval') {
				// If we are working with fkey, fkey_multi, object, object_multi, then use _fval version
				if (isset($data[$fieldName . "_fval"])) {
					$entity->$fieldName = $data[$fieldName . "_fval"];
				} else {
					$entity->$fieldName = $fieldValue;
				}
			}
		}

		return $entity;
	}

	/**
     * Initialize heiarachial array of groupings
     * 
     * @param type $groupsData
     * @return EntityGrouping[]
     */
    private function loadEntityGropingFromData($groupsData)
    {
        $groupings = array();

        if ($groupsData && !isset($groupsData->error))
        {
            foreach ($groupsData as $grpData)
            {
                $grp = new EntityGrouping();
                
                foreach ($grpData as $fname=>$fval)
                {
                    switch($fname)
                    {
                    case "heiarch":
                        $fname = "isHeiarch";
                        break;
                    case "parent_id":
                        $fname = "parantId";
                        break;
                    case "sort_order":
                        $fname = "sortOrder";
                        break;
                    default:
                        break;
                    }
                    
                    $grp->setValue($fname, $fval);
                }
                
				if (isset($grpData->children))
                	$grp->children = $this->loadEntityGropingFromData($grpData->children);
                
                $groupings[] = $grp;
            }
        }
        
        return $groupings;
    }
}
