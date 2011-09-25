<?php

class App_Amazon_Route53 extends Zend_Service_Amazon_Abstract
{
	protected $_endpoint;
	const ROUTE53_ENDPOINT = 'route53.amazonaws.com';
	const API_VERSION = '2010-10-01';
	protected $_apiVersion;
	
	protected $_errorMessage = '';
	protected $_errorCode = '';
	
	public function setEndpoint($endpoint)
    {
        if (!($endpoint instanceof Zend_Uri_Http)) {
            $endpoint = Zend_Uri::factory($endpoint);
        }
        if (!$endpoint->valid()) {
            throw new Zend_Exception('Invalid endpoint supplied');
        }
        $this->_endpoint = $endpoint;
        return $this;
    }

    public function getEndpoint()
    {
        return $this->_endpoint;
    }

    public function __construct($accessKey=null, $secretKey=null, $apiVersion=null)
    {
        parent::__construct($accessKey, $secretKey, null);
		
		if($apiVersion !== null)
		{
			$this->_apiVersion = $apiVersion;
		}
		else
		{
			$this->_apiVersion = self::API_VERSION;
		}
		
        $this->setEndpoint('https://'.self::ROUTE53_ENDPOINT);
    }
    
    public function listHostedZones($marker = null, $maxItems = null)
    {
    	$params = array();
    	if($marker !== null)
    	{
    		$params['marker'] = $marker;
    	}
    	if($maxItems !== null)
    	{
    		$params['maxitems'] = $maxItems;
    	}
    	$response = $this->_makeRequest('GET', 'hostedzone', $params);
    	
		if($response->getStatus()  !== 200)
		{
			$this->_setError($response);
			return false;
		}
    	
    	$xml = new SimpleXMLElement($response->getBody());
    	
    	$zones = array(
    		'MaxItems' => (int) $xml->MaxItems,
    		'IsTruncated' => ((string)$xml->IsTruncated) == 'true' ? true : false,
    		'NextMarker' => ((string)$xml->IsTruncated) == 'true' ? (string) $xml->NextMarker : NULL,
    		'HostedZones' => array()
    	);
    	
    	foreach($xml->HostedZones->HostedZone as $zone)
    	{
    		$zones['HostedZones'][] = array(
    			'Id' => (string) $zone->Id,
    			'Name' => (string) $zone->Name,
    			'CallerReference' => (string) $zone->CallerReference,
    			'Comment' => (string) $zone->Config->Comment
    		);
    	}
    	
    	return $zones;
    }
    
    public function createHostedZone($name, $comment = '')
    {
    	if( ! ($name[strlen($name) - 1] == '.'))
    	{
    		$name .= '.';
    	}
		$uniqid = uniqid($name);
    	$request = '<?xml version="1.0" encoding="UTF-8"?>
<CreateHostedZoneRequest xmlns="' . $this->_endpoint . '/doc/' . $this->_apiVersion . '/">
   <Name>' . $name . '</Name>
   <CallerReference>' . $uniqid . '</CallerReference>
   <HostedZoneConfig>
      <Comment>' . $comment . '</Comment>
   </HostedZoneConfig>
</CreateHostedZoneRequest>';

		$response = $this->_makeRequest('POST', 'hostedzone', null, $request);

		if($response->getStatus()  !== 201)
		{
			$this->_setError($response);
			return false;
		}

		$xml = new SimpleXMLElement($response->getBody());
		$hostedZone = array(
			'HostedZone' => array(
				'Id' => (string) $xml->HostedZone->Id,
				'Name' => (string) $xml->HostedZone->Name,
				'CallerReference' => (string) $xml->HostedZone->CallerReference,
				'Comment' => (string) $xml->HostedZone->Config->Comment
			),
			'ChangeInfo' => array(
				'Id' => (string) $xml->ChangeInfo->Id,
				'Status' => (string) $xml->ChangeInfo->Status,
				'SubmittedAt' => (string) $xml->ChangeInfo->SubmittedAt
			),
			'NameServers' => array()
		);
		
		foreach($xml->DelegationSet->NameServers->NameServer as $ns)
		{
			$hostedZone['NameServers'][] = (string) $ns;
		}
		
		return $hostedZone;
    }
    
    public function deleteHostedZone($zone)
    {
    	$response = $this->_makeRequest('DELETE', $zone);
    	
    	if($response->getStatus()  !== 200)
		{
			$this->_setError($response);
			return false;
		}
		
		$xml = new SimpleXMLElement($response->getBody());
		$zone = array(
			'Id' => (string) $xml->ChangeInfo->Id,
			'Status' => (string) $xml->ChangeInfo->Status,
			'SubmittedAt' => (string) $xml->ChangeInfo->SubmittedAt
		);
		
		return $zone;
    }
    
    public function getHostedZone($zone)
    {
    	$response = $this->_makeRequest('GET', $zone);

		if($response->getStatus()  !== 200)
		{
			$this->_setError($response);
			return false;
		}

		$xml = new SimpleXMLElement($response->getBody());
		$hostedZone = array(
			'HostedZone' => array(
				'Id' => (string) $xml->HostedZone->Id,
				'Name' => (string) $xml->HostedZone->Name,
				'CallerReference' => (string) $xml->HostedZone->CallerReference,
				'Comment' => (string) $xml->HostedZone->Config->Comment
			),
			'NameServers' => array()
		);
		
		foreach($xml->DelegationSet->NameServers->NameServer as $ns)
		{
			$hostedZone['NameServers'][] = (string) $ns;
		}
		
		return $hostedZone;
    }
    
    function changeResourceRecordSets($zone, $recordSet, $comment = '')
    {
    
    	if( ! $recordSet)
    	{
    		throw new Zend_Exception('No recordset specified');
    	}
    
    	$request = '<?xml version="1.0" encoding="UTF-8"?>
<ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2010-10-01/">
   <ChangeBatch>
      <Comment>' . $comment . '</Comment>
      <Changes>';
      
      	foreach($recordSet as $record)
      	{
      		$request .= '
      	<Change>
            <Action>' . $record['Action'] . '</Action>
            <ResourceRecordSet>
               <Name>' . $record['Name'] . '</Name>
               <Type>' . $record['Type'] . '</Type>
               <TTL>' . $record['TTL'] . '</TTL>
               <ResourceRecords>
                  <ResourceRecord>
                     <Value>' . $record['Value'] . '</Value>
                  </ResourceRecord>
               </ResourceRecords>
            </ResourceRecordSet>
         </Change>';
      	}
      	
      	$request .= '</Changes>
   </ChangeBatch>
</ChangeResourceRecordSetsRequest>';

		$response = $this->_makeRequest('POST', $zone . '/rrset', null, $request);
		
		if($response->getStatus() !== 200)
		{
			$this->_setError($response);
			return false;
		}
		
		$xml = new SimpleXMLElement($response->getBody());
		
		return array(
			'Id' => (string) $xml->ChangeInfo->Id,
			'Status' => (string) $xml->ChangeInfo->Status,
			'SubmittedAt' => (string) $xml->ChangeInfo->SubmittedAt
		);
    }
    
    public function listResourceRecordSets($zone, $name = null, $type = null, $maxitems = null)
    {
    	$params = array();
    	if($name !== null)
    	{
    		$params['name'] = $name;
    	}
    	if($type !== null)
    	{
    		$params['type'] = $type;
    	}
    	if($maxitems !== null)
    	{
    		$params['maxitems'] = $maxitems;
    	}
    	
    	$response = $this->_makerequest('GET', $zone . '/rrset', $params);
    	
    	if($response->getStatus() !== 200)
		{
			$this->_setError($response);
			return false;
		}
		
		$xml = new SimpleXMLElement($response->getBody());
		
		$recordSet = array(
			'IsTruncated' => ( (string) $xml->IsTruncated ) == 'true' ? true : false,
			'MaxItems' => (int) $xml->MaxItems,
			'NextRecordName' => (string) $xml->NextRecordName,
			'NextRecordType' => (string) $xml->NextRecordType,
			'RecordSet' => array()
		);
		
		foreach($xml->ResourceRecordSets->ResourceRecordSet as $record)
		{
			$set = array(
				'Name' => (string) $record->Name,
				'Type' => (string) $record->Type,
				'TTL' => (string) $record->TTL,
				'Value' => array()
			);
			
			foreach($record->ResourceRecords->ResourceRecord as $res)
			{
				$set['Value'][] = (string) $res->Value;
			}
			
			$recordSet['RecordSet'][] = $set;
		}
		
		return $recordSet;
    }
    
    public function getChange($change)
    {
    	$response = $this->_makeRequest('GET', $change);
    	
    	if($response->getStatus() !== 200)
		{
			$this->_setError($response);
			return false;
		}
		
		$xml = new SimpleXMLElement($response->getBody());
		
		return array(
			'Id' => (string) $xml->ChangeInfo->Id,
			'Status' => (string) $xml->ChangeInfo->Status,
			'SubmittedAt' => (string) $xml->ChangeInfo->SubmittedAt
		);
    }
    
    public function _makeRequest($method, $path, $params = null, $data = null)
    {
    	$retry_count = 0;

        $date = gmdate('D, d M Y H:i:s e');

		$headers = array();
		$headers[] = 'Date: '.$date;

		$headers['X-Amzn-Authorization'] = $this->_getAuth($date);
		if($path[0] == '/')
		{
			$path = substr($path, -(strlen($path) - 1));
		}
		$endpoint = $this->_endpoint . '/' . $this->_apiVersion . '/' . ( (string) $path );
        $client = self::getHttpClient();

        $client->resetParameters();
        $client->setUri($endpoint);
        $client->setAuth(false);
        // Work around buglet in HTTP client - it doesn't clean headers
        // Remove when ZHC is fixed
        $client->setHeaders(array('Content-MD5'              => null,
                                  'Content-Encoding'         => null,
                                  'Expect'                   => null,
                                  'Range'                    => null,
                                  'x-amz-acl'                => null,
                                  'x-amz-copy-source'        => null,
                                  'x-amz-metadata-directive' => null));

        $client->setHeaders($headers);

		if(is_array($params))
		{
        	$client->setParameterGet($params);
        }

         if ($data !== null) {
             if (!isset($headers['Content-type'])) {
                 $headers['Content-type'] = 'text/xml';
             }
             $client->setRawData($data, $headers['Content-type']);
         }
         
         do {
            $retry = false;
            $response = $client->request($method);
            $response_code = $response->getStatus();

            if ($response_code >= 500 && $response_code < 600 && $retry_count <= 5) {
                $retry = true;
                $retry_count++;
                sleep($retry_count / 4 * $retry_count);
            }
            else if ($response_code == 307) {
            }
            else if ($response_code == 100) {
            }
        } while ($retry);

        return $response;
    }
    
    protected function _setError(Zend_Http_Response $response)
    {
    	$xml = new SimpleXMLElement($response->getBody());
    	
		if(isset($xml->Messages->Message))
		{
			$this->_errorMessage = array();
			foreach($xml->Messages->Message as $message)
			{
				$this->_errorMessage[] = $message;
			}
			return ;
		}
    	
    	$this->_errorCode = (string) $xml->Error->Code;
    	$this->_errorMessage = (string) $xml->Error->Message;
    }
    
    public function getErrorMessage()
    {
    	return $this->_errorMessage;
    }
    
    public function getErrorCode()
    {
    	return $this->_errorCode;
    }
    
    protected function _getAuth($date)
    {
    	$auth = 'AWS3-HTTPS AWSAccessKeyId='.$this->_getAccessKey();
		$auth .= ',Algorithm=HmacSHA256,Signature='.base64_encode(hash_hmac('sha256', $date, $this->_getSecretKey(), true));
		return $auth;
    }
}