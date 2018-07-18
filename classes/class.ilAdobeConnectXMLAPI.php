<?php
include_once './Customizing/global/plugins/Services/Repository/RepositoryObject/AdobeConnect/classes/class.ilAdobeConnectServer.php';

/**
 * Connect to Adobe Connect API
 *
 * @author Nadia Matuschek <nmatuschek@databay.de>
 *        
 */
class ilAdobeConnectXMLAPI
{

	/**
	 * Adobe Connect server
	 *
	 * @var String
	 */
	protected $server;

	/**
	 *
	 * @var String
	 */
	protected $port;

	/**
	 *
	 * @var null
	 */
	protected $x_user_id;

	/**
	 *
	 * @var ilAdobeConnectServer
	 */
	protected $adcInfo;

	/**
	 *
	 * @var null
	 */
	protected static $breeze_session = null;

	/**
	 *
	 * @var null
	 */
	protected $auth_mode = null;

	/**
	 *
	 * @var array
	 */
	protected static $loginsession_cache = array();

	/**
	 *
	 * @var array
	 */
	protected static $scocontent_cache = array();

	/**
	 *
	 * @var \ilLog
	 */
	protected $logger;

	/**
	 *
	 * @var \ilLanguage
	 */
	protected $lng;

	/**
	 * ilAdobeConnectXMLAPI constructor.
	 */
	public function __construct()
	{
		global $DIC;
		$this->adcInfo = ilAdobeConnectServer::_getInstance();
		$this->server = $this->adcInfo->getServer();
		$this->port = $this->adcInfo->getPort();
		$this->x_user_id = $this->adcInfo->getXUserId();
		$this->auth_mode = $this->adcInfo->getAuthMode();
		
		$this->logger = $DIC->logger();
		$this->lng = $DIC->language();
		
		$this->proxy();
	}

	/**
	 *
	 * @return null|string
	 */
	public function getXUserId()
	{
		return $this->x_user_id;
	}

	/**
	 *
	 * @return null|string
	 */
	public function getAdminSession()
	{
		$session = $this->getBreezeSession();
		
		if (! $session) {
			/**
			 *
			 * @todo introduce exception
			 */
			return null;
		}
		
		$success = $this->login($this->adcInfo->getLogin(), $this->adcInfo->getPasswd(), $session);
		
		if ($success) {
			return $session;
		} else {
			/**
			 *
			 * @todo introduce exception
			 */
			return null;
		}
	}

	/**
	 * Logs in user on the Adobe Connect server.
	 * The session id is caches until the
	 * logout function is called with the session id.
	 *
	 * @param String $user
	 *        	Adobe Connect user login
	 * @param String $pass
	 *        	Adobe Connect user password
	 * @param String $session
	 *        	Session id
	 * @return boolean return true if everything is ok
	 */
	public function login($user, $pass, $session)
	{
		if (isset(self::$loginsession_cache[$session]) && self::$loginsession_cache[$session]) {
			return self::$loginsession_cache[$session];
		}
		
		if (isset($user, $pass, $session)) {
			$url = $this->getApiUrl(array(
				'action' => 'login',
				'login' => $user,
				'password' => $pass,
				'session' => $session
			));
			
			$context = (array(
				'http' => array(
					'timeout' => 4
				),
				'https' => array(
					'timeout' => 4
				)
			));
			
			$ctx = $this->proxy($context);
			$xml_string = file_get_contents($url, false, $ctx);
			$xml = simplexml_load_string($xml_string);
			
			if ($xml->status['code'] == 'ok') {
				self::$loginsession_cache[$session] = true;
				return true;
			} else {
				unset(self::$loginsession_cache[$session]);
				$this->logger->write('AdobeConnect login Request: ' . $url);
				if ($xml) {
					$this->logger->write('AdobeConnect login Response: ' . $xml->asXML());
				}
				$this->logger->write('AdobeConnect login failed: ' . $user);
				ilUtil::sendFailure($this->lng->txt('login_failed'));
				return false;
			}
		} else {
			unset(self::$loginsession_cache[$session]);
			$this->logger->write('AdobeConnect login failed due to missing login credentials ...');
			ilUtil::sendFailure($this->lng->txt('err_wrong_login'));
			return false;
		}
	}

	/**
	 * Changes the password of the user, identified by username
	 *
	 * @param string $username
	 * @param string $newPassword
	 *
	 * @return boolean true on success
	 */
	public function changeUserPassword($username, $newPassword)
	{
		$user_id = $this->searchUser($username, $this->getAdminSession());
		
		if ($user_id) {
			$url = $this->getApiUrl(array(
				'action' => 'user-update-pwd',
				'session' => $this->getAdminSession(),
				'password' => $newPassword,
				'password-verify' => $newPassword,
				'user-id' => $user_id
			));
			$xml = simplexml_load_file($url);
			
			return $xml instanceof SimpleXMLElement && $xml->status['code'] == 'ok';
		}
		return false;
	}

	/**
	 * Logs in user on Adobe Connect server using external authentication
	 *
	 * @ilObjUser $ilUser
	 * @param null $user
	 * @param null $pass
	 * @param null $session
	 * @return bool|mixed|null|String
	 */
	public function externalLogin($user = null, $pass = null, $session = null)
	{
		if ($this->adcInfo->getAuthMode() == ilAdobeConnectServer::AUTH_MODE_HEADER) {
			$auth_result = $this->useHTTPHeaderAuthentification($user);
			return $auth_result;
		} else // default: auth_mode_password
		{
			$auth_result = $this->usePasswordAuthentication($user);
			return $auth_result;
		}
	}

	/**
	 * Logs out user on the Adobe Connect server
	 *
	 * @param String $session
	 *        	Session id
	 * @return boolean return true if everything is ok
	 */
	public function logout($session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'logout',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		if ($session == self::$breeze_session) {
			self::$breeze_session = null;
		}
		
		unset(self::$loginsession_cache[$session]);
		
		if ($xml->status['code'] == "ok") {
			return true;
		} else {
			$this->logger->write('AdobeConnect logout Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect logout Response: ' . $xml->asXML());
			}
			
			return false;
		}
	}

	/**
	 *
	 * @param bool $useCache
	 * @return null|string
	 */
	public function getBreezeSession($useCache = true)
	{
		if (null !== self::$breeze_session && $useCache) {
			return self::$breeze_session;
		}
		
		$url = $this->getApiUrl(array(
			'action' => 'common-info'
		));
		
		$context = array(
			'http' => array(
				'timeout' => 4
			),
			'https' => array(
				'timeout' => 4
			)
		);
		$ctx = $this->proxy($context);
		$xml_string = file_get_contents($url, false, $ctx);
		$xml = simplexml_load_string($xml_string);
		
		if ($xml && $xml->common->cookie != "") {
			$session = (string) $xml->common->cookie;
			if (! $useCache) {
				return $session;
			}
			
			self::$breeze_session = $session;
			return self::$breeze_session;
		} else {
			$this->logger->write('AdobeConnect getBreezeSession Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getBreezeSession Response: ' . $xml->asXML());
			}
			
			return null;
		}
	}

	/**
	 * Returns the id associated with the object type parameter
	 *
	 * @param String $type
	 *        	Object type
	 * @param String $session
	 *        	Session id
	 * @return String Object id
	 */
	public function getShortcuts($type, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-shortcuts',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		if ($xml instanceof SimpleXMLElement && 'ok' == (string) $xml->status['code']) {
			foreach ($xml->shortcuts->sco as $sco) {
				if ($sco['type'] == $type) {
					$id = (string) $sco['sco-id'];
				}
			}
		}
		return ($id == "" ? NULL : $id);
	}

	/**
	 * Returns the folder_id
	 *
	 * @param Integer $scoId
	 *        	sco-id
	 * @param String $session
	 *        	Session id
	 * @return String Object id
	 */
	public function getFolderId($scoId, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-info',
			'session' => $session,
			'sco-id' => $scoId
		));
		
		$xml = simplexml_load_file($url);
		$id = $xml->sco['folder-id'];
		
		return ($id == "" ? NULL : $id);
	}

	/**
	 * Adds a new meeting on the Adobe Connect server
	 *
	 * @param String $name
	 *        	Meeting name
	 * @param String $description
	 *        	Meeting description
	 * @param String $start_date
	 *        	Meeting start date
	 * @param String $start_time
	 *        	Meeting start time
	 * @param String $end_date
	 *        	Meeting end date
	 * @param String $end_time
	 *        	Meeting end time
	 * @param String $end_time
	 *        	Meeting lang
	 * @param String $folder_id
	 *        	Sco-id of the user's meetings folder
	 * @param String $session
	 *        	Session id
	 * @return array Meeting sco-id AND Meeting url-path; NULL if something is wrong
	 * @throws ilException
	 */
	public function addMeeting($name, $description, $start_date, $start_time, $end_date, $end_time, $meeting_lang, $folder_id, $session, $source_sco_id = 0)
	{
		$api_parameter = array(
			'action' => 'sco-update',
			'type' => 'meeting',
			'name' => $name,
			'lang' => $meeting_lang,
			'folder-id' => $folder_id,
			'description' => $description,
			'date-begin' => $start_date . "T" . $start_time,
			'date-end' => $end_date . "T" . $end_time,
			'session' => $session
		);
		
		if ($source_sco_id > 0) {
			$api_parameter['source-sco-id'] = (string) $source_sco_id;
		}
		
		$url = $this->getApiUrl($api_parameter);
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return array(
				'meeting_id' => (string) $xml->sco['sco-id'],
				'meeting_url' => (string) $xml->sco->{'url-path'}
			);
		} else {
			$this->logger->write('AdobeConnect addMeeting Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect addMeeting Response: ' . $xml->asXML());
				foreach ($xml->status->{'invalid'}->attributes() as $key => $value) {
					if ($key == 'subcode' && $value == 'duplicate') {
						throw new ilException('err_duplicate_meeting');
						return NULL;
					}
				}
			}
			
			return NULL;
		}
	}

	/**
	 * Updates an existing meeting
	 *
	 * @param String $meeting_id
	 * @param String $name
	 * @param String $description
	 * @param String $start_date
	 * @param String $start_time
	 * @param String $end_date
	 * @param String $end_time
	 * @param String $lang
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function updateMeeting($meeting_id, $name, $description, $start_date, $start_time, $end_date, $end_time, $language, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-update',
			'sco-id' => $meeting_id,
			'name' => $name,
			'description' => $description,
			'date-begin' => $start_date . "T" . $start_time,
			'date-end' => $end_date . "T" . $end_time,
			'lang' => $language,
			'session' => $session
		));
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == 'ok') {
			return true;
		} else {
			$this->logger->write('AdobeConnect updateMeeting Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect updateMeeting Response: ' . $xml->asXML());
			}
			ilUtil::SendFailure($this->lng->txt('update_meeting_failed'));
			return false;
		}
	}

	/**
	 * Deletes an existing meeting
	 *
	 * @param String $sco_id
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function deleteMeeting($sco_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-delete',
			'sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		// 'no-data' means current sco does not exists or sco is already deleted
		if (($xml->status['code'] == 'ok') || ($xml->status['code'] == 'no-data')) {
			return true;
		} else {
			$this->logger->write('AdobeConnect deleteMeeting Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect deleteMeeting Response: ' . $xml->asXML());
			}
			ilUtil::sendFailure($this->lng->txt('delete_meeting_failed'));
			return false;
		}
	}

	/**
	 * Sets meeting to private
	 * Only registered users and participants can enter (no guests!!)
	 *
	 * @param integer $a_meeting_id
	 *        	Meeting id
	 * @return boolean Returns true if everything is ok
	 */
	public function setMeetingPrivate($a_meeting_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $a_meeting_id,
			'principal-id' => 'public-access',
			'permission-id' => 'denied',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return true;
		} else {
			$this->logger->write('AdobeConnect setMeetingPrivate Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect setMeetingPrivate Response: ' . $xml->asXML());
			}
			
			return false;
		}
	}

	/**
	 * Everyone can enter!!!
	 *
	 * @param $a_meeting_id $meeting_id
	 * @return boolean
	 */
	public function setMeetingPublic($a_meeting_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $a_meeting_id,
			'principal-id' => 'public-access',
			'permission-id' => 'view-hidden',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return true;
		} else {
			$this->logger->write('AdobeConnect setMeetingPublic Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect setMeetingPublic Response: ' . $xml->asXML());
			}
			
			return false;
		}
	}

	/**
	 * Only registered users and accepted guests can enter (default)
	 *
	 * @param integer $a_meeting_id
	 * @return boolean
	 */
	public function setMeetingProtected($a_meeting_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $a_meeting_id,
			'principal-id' => 'public-access',
			'permission-id' => 'remove',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return true;
		} else {
			$this->logger->write('AdobeConnect setMeetingProtected Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect setMeetingProtected Response: ' . $xml->asXML());
			}
			
			return false;
		}
	}

	public function updatePermission($a_meeting_id, $session, $a_permission_id)
	{
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $a_meeting_id,
			'principal-id' => 'public-access',
			'permission-id' => $a_permission_id,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return true;
		} else {
			$this->logger->write('AdobeConnect updatePermission Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect updatePermission Response: ' . $xml->asXML());
			}
			return false;
		}
	}

	/**
	 * Gets meeting or content URL
	 *
	 * @param String $sco_id
	 *        	Meeting or content id
	 * @param String $folder_id
	 *        	Parent folder id
	 * @param String $session
	 *        	Session id
	 * @param String $type
	 *        	Used for SWITCHaai meeting|content|...
	 * @return String Meeting or content URL, or NULL if something is wrong
	 */
	public function getURL($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		$xml = $this->getCachedSessionCall($url);
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'url-path'};
		}
		
		$this->logger->write('AdobeConnect getURL Request: ' . $url);
		if ($xml) {
			$this->logger->write('AdobeConnect getURL Response: ' . $xml->asXML());
		}
		return NULL;
	}

	/**
	 * Gets meeting start date
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting start date, or NULL if something is wrong
	 */
	public function getStartDate($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'date-begin'};
		} else {
			$this->logger->write('AdobeConnect getStartDate Request: ' . $url);
			$this->logger->write('AdobeConnect getStartDate Response: ' . $xml->asXML());
			
			return NULL;
		}
	}

	public function isActiveSco($session, $sco_id)
	{
		$url = $this->getApiUrl(array(
			'action' => 'report-active-meetings',
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		$counter = 0;
		$result = array();
		if (is_array($xml->{'report-active-meetings'}->sco)) {
			foreach ($xml->{'report-active-meetings'}->sco as $sco) {
				foreach ($sco->attributes() as $name => $attr) {
					$result[$counter][(string) $name] = (string) $attr;
				}
				$counter ++;
			}
			
			return $result;
		}
		return 0;
	}

	public function getActiveScos($session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'report-active-meetings',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		$counter = 0;
		$result = array();
		if ($xml->{'report-active-meetings'}->sco) {
			foreach ($xml->{'report-active-meetings'}->sco as $sco) {
				foreach ($sco->attributes() as $name => $attr) {
					$result[$counter][(string) $name] = (string) $attr;
				}
				
				$result[$counter]['name'] = (string) $sco->name;
				$result[$counter]['sco_url'] = (string) $sco->{'url-path'};
				
				$counter ++;
			}
			
			return $result;
		}
		return 0;
	}

	public function getAllScos($session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'report-bulk-objects',
			'filter-type' => 'meeting',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		$result = array();
		
		if ($xml->{'report-bulk-objects'}) {
			foreach ($xml->{'report-bulk-objects'}->row as $meeting) {
				if ($meeting->{'date-end'} != '') {
					$result[(string) $meeting['sco-id']]['sco_id'] = (string) $meeting['sco-id'];
					$result[(string) $meeting['sco-id']]['sco_name'] = (string) $meeting->{'name'};
					$result[(string) $meeting['sco-id']]['description'] = (string) $meeting->{'description'};
					$result[(string) $meeting['sco-id']]['sco_url'] = (string) $meeting->{'url'};
					$result[(string) $meeting['sco-id']]['date_end'] = (string) $meeting->{'date-end'};
				}
			}
			
			return $result;
		}
		return 0;
	}

	/**
	 * Gets meeting end date
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting start date, or NULL if something is wrong
	 */
	public function getEndDate($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'date-end'};
		} else {
			$this->logger->write('AdobeConnect getStartDate Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getStartDate Response: ' . $xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * Gets meeting or content name
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting or content name, or NULL if something is wrong
	 */
	public function getName($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'name'};
		} else {
			$this->logger->write('AdobeConnect getName Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getName Response: ' . $xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * Gets meeting or content description
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting or content description, or NULL if something is wrong
	 */
	public function getDescription($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'description'};
		} else {
			$this->logger->write('AdobeConnect getDescription Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getDescription Response: ' . $xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * Gets meeting or content creation date
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting or content creation date, or NULL if something is wrong
	 */
	public function getDateCreated($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'date-created'};
		} else {
			$this->logger->write('AdobeConnect getDateCreated Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getDateCreated Response: ' . $xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * Gets meeting or content modification date
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting or content modification date, or NULL if something is wrong
	 */
	public function getDateModified($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'date-modified'};
		} else {
			$this->logger->write('AdobeConnect getDateModified Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getDateModified Response: ' . $xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * Gets content duration
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Content duration, or NULL if something is wrong
	 */
	public function getDuration($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->duration;
		} else {
			$this->logger->write('AdobeConnect getDuration Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getDuration Response: ' . $xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * Returns all identifiers of content associated with the meeting
	 *
	 * @param String $meeting_id
	 * @param String $session
	 * @return array
	 */
	public function getContentIds($meeting_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $meeting_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			$ids = array();
			$i = 0;
			$contents = array();
			$records = array();
			
			foreach ($xml->scos->sco as $sco) {
				if ($sco['source-sco-id'] == "" && $sco['duration'] == "") {
					$contents[$i] = (string) $sco['sco-id'];
					$i ++;
				} else if ($sco['source-sco-id'] == "" && $sco['duration'] != "") {
					$records[$i] = (string) $sco['sco-id'];
					$i ++;
				}
			}
			return array_merge($contents, $records);
		} else {
			$this->logger->write('AdobeConnect getDuration Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getDuration Response: ' . $xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * Returns all identifiers of record associated with the meeting
	 *
	 * @param String $meeting_id
	 * @param String $session
	 * @return array
	 */
	public function getRecordIds($meeting_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $meeting_id,
			'session' => $session
		));
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			$ids = array();
			$i = 0;
			foreach ($xml->scos->sco as $sco) {
				if ($sco['source-sco-id'] == "" && $sco['duration'] != "") {
					$ids[$i] = (string) $sco['sco-id'];
					$i ++;
				}
			}
			return $ids;
		}
		return NULL;
	}

	/**
	 * Change the visibility of a content or a recording to public and back to private
	 *
	 * @param String $sco_id
	 * @param String $session
	 * @param String $permission
	 *        	Permission to change to
	 * @return Returns TRUE on success and FALSE on faliure
	 */
	public function changeContentVisibility($sco_id, $session, $permission)
	{
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $sco_id,
			'principal-id' => 'public-access',
			'permission-id' => $permission,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return true;
		} else {
			$this->logger->write('AdobeConnect setMeetingPublic Request: ' . $url);
			
			if ($xml) {
				$this->logger->write('AdobeConnect setMeetingPublic Response: ' . $xml->asXML());
			}
			
			return false;
		}
	}

	/**
	 * Gets meeting or content language
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting or Content language, or NULL if something went wrong
	 */
	public function getMeetingLang($sco_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-info',
			'sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->sco['lang'];
		} else {
			$this->logger->write('AdobeConnect getName Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getName Response: ' . $xml->asXML());
			}
			
			return NULL;
		}
	}

	/**
	 * Adds a content associated with the meeting
	 *
	 * @param String $folder_id
	 * @param String $title
	 * @param String $description
	 * @param String $session
	 * @return String
	 * @throws ilAdobeConnectDuplicateContentException
	 */
	public function addContent($folder_id, $title, $description, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-update',
			'name' => $title,
			'folder-id' => $folder_id,
			'description' => $description,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		if ($xml instanceof SimpleXMLElement && $xml->status['code'] == 'ok') {
			$server = $this->server;
			if (substr($server, - 1) == '/') {
				$server = substr($server, 0, - 1);
			}
			return $server . "/api/xml?action=sco-upload&sco-id=" . (string) $xml->sco['sco-id'] . "&session=" . $session;
		} else {
			$this->logger->write('AdobeConnect addContent Request: ' . $url);
			
			if ($xml instanceof SimpleXMLElement) {
				$this->logger->write('AdobeConnect addContent Response: ' . $xml->asXML());
				
				if ($xml->status['code'] == 'invalid' && $xml->status->invalid['subcode'] == 'duplicate') {
					throw new ilAdobeConnectDuplicateContentException('add_cnt_err_duplicate');
				}
			}
			
			return NULL;
		}
	}

	/**
	 * Updates a content
	 *
	 * @param String $sco_id
	 * @param String $title
	 * @param String $description
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 * @throws ilAdobeConnectDuplicateContentException
	 */
	public function updateContent($sco_id, $title, $description, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-update',
			'name' => $title,
			'sco-id' => $sco_id,
			'description' => $description,
			'session' => $session
		));
		$xml = simplexml_load_file($url);
		
		if ($xml instanceof SimpleXMLElement && $xml->status['code'] == 'ok') {
			return true;
		} else {
			$this->logger->write('AdobeConnect updateContent Request: ' . $url);
			
			if ($xml instanceof SimpleXMLElement) {
				$this->logger->write('AdobeConnect updateContent Response: ' . $xml->asXML());
				
				if ($xml->status['code'] == 'invalid' && $xml->status->invalid['subcode'] == 'duplicate') {
					throw new ilAdobeConnectDuplicateContentException('add_cnt_err_duplicate');
				}
			}
			
			return false;
		}
	}

	/**
	 * Deletes a content
	 *
	 * @param String $sco_id
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function deleteContent($sco_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-delete',
			'sco-id' => $sco_id,
			'session' => $session
		));
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return true;
		} else {
			$this->logger->write('AdobeConnect deleteContent Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect deleteContent Response: ' . $xml->asXML());
			}
			return false;
		}
	}

	/**
	 * Upload a content on the Adobe Connect server
	 *
	 * @param
	 *        	$sco_id
	 * @param String $session
	 * @return String
	 */
	public function uploadContent($sco_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-upload',
			'sco-id' => $sco_id,
			'session' => $session
		));
		
		return $url;
	}

	/**
	 *
	 * @param string $login
	 * @param string $session
	 * @return bool|string
	 */
	public function searchUser($login, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'report-bulk-users',
			'filter-login' => $login,
			'session' => $session
		));
		$xml = simplexml_load_file($url);
		
		if ($xml->{'report-bulk-users'}->row['principal-id'] != '') {
			return (string) $xml->{'report-bulk-users'}->row['principal-id'];
		} else {
			// user doesn't exist at adobe connect server
			$this->logger->write('AdobeConnect searchUser Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect searchUser Response: ' . $xml->asXML());
			}
			return false;
		}
	}

	/**
	 * Adds a user to the Adobe Connect server
	 *
	 * @param String $login
	 * @param String $email
	 * @param String $pass
	 * @param String $first_name
	 * @param String $last_name
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function addUser($login, $email, $pass, $first_name, $last_name, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'principal-update',
			'login' => $login,
			'email' => $email,
			'password' => $pass,
			'first-name' => $first_name,
			'last-name' => $last_name,
			'type' => 'user',
			'has-children' => 0,
			'session' => $session
		));
		$this->logger->write("addUser Url: " . $url);
		
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == 'ok') {
			return true;
		} else {
			$this->logger->write('AdobeConnect addUser Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect addUser Response: ' . $xml->asXML());
			}
			
			return false;
		}
	}

	/**
	 * Return meetings hosts
	 *
	 * @param String $meeting_id
	 * @param String $session
	 * @return array
	 */
	public function getMeetingsParticipants($meeting_id, $session)
	{
		$result = array();
		
		if ($this->auth_mode == ilAdobeConnectServer::AUTH_MODE_HEADER) {
			$host = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-permission-id' => 'host'
			));
			$mini_host = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-permission-id' => 'mini-host'
			));
			$view = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-permission-id' => 'view'
			));
			
			$denied = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-permission-id' => 'denied'
			));
		} else {
			$host = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-type' => 'user',
				'filter-permission-id' => 'host'
			));
			$mini_host = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-type' => 'user',
				'filter-permission-id' => 'mini-host'
			));
			$view = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-type' => 'user',
				'filter-permission-id' => 'view'
			));
			$denied = $this->getApiUrl(array(
				'action' => 'permissions-info',
				'acl-id' => $meeting_id,
				'session' => $session,
				'filter-type' => 'user',
				'filter-permission-id' => 'denied'
			));
		}
		
		$xml_host = simplexml_load_file($host);
		foreach ($xml_host->permissions->principal as $user) {
			$result[(string) $user->login] = array(
				"name" => (string) $user->name,
				"login" => (string) $user->login,
				'status' => 'host'
			);
		}
		
		$xml_mini_host = simplexml_load_file($mini_host);
		foreach ($xml_mini_host->permissions->principal as $user) {
			$result[(string) $user->login] = array(
				"name" => (string) $user->name,
				"login" => (string) $user->login,
				'status' => 'mini-host'
			);
		}
		
		$xml_view = simplexml_load_file($view);
		foreach ($xml_view->permissions->principal as $user) {
			$result[(string) $user->login] = array(
				"name" => (string) $user->name,
				"login" => (string) $user->login,
				'status' => 'view'
			);
		}
		
		$xml_denied = simplexml_load_file($denied);
		foreach ($xml_denied->permissions->principal as $user) {
			$result[(string) $user->login] = array(
				"name" => (string) $user->name,
				"login" => (string) $user->login,
				'status' => 'denied'
			);
		}
		
		return is_array($result) ? $result : array();
	}

	/**
	 * Add a host to the meeting
	 *
	 * @param String $meeting_id
	 * @param String $login
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function addMeetingParticipant($meeting_id, $login, $session)
	{
		$principal_id = $this->getPrincipalId($login, $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $meeting_id,
			'session' => $session,
			'principal-id' => $principal_id,
			'permission-id' => 'view'
		));
		
		$xml = simplexml_load_file($url);
		
		return ($xml->status['code'] == "ok" ? true : false);
	}

	/**
	 * Add a host to the meeting
	 *
	 * @param String $meeting_id
	 * @param String $login
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function addMeetingHost($meeting_id, $login, $session)
	{
		$principal_id = $this->getPrincipalId($login, $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $meeting_id,
			'session' => $session,
			'principal-id' => $principal_id,
			'permission-id' => 'host'
		));
		$xml = simplexml_load_file($url);
		
		return ($xml->status['code'] == "ok" ? true : false);
	}

	/**
	 * Add a moderator to the meeting
	 *
	 * @param String $meeting_id
	 * @param String $login
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function addMeetingModerator($meeting_id, $login, $session)
	{
		$principal_id = $this->getPrincipalId($login, $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $meeting_id,
			'session' => $session,
			'principal-id' => $principal_id,
			'permission-id' => 'mini-host'
		));
		$xml = simplexml_load_file($url);
		
		return ($xml->status['code'] == "ok" ? true : false);
	}

	public function updateMeetingParticipant($meeting_id, $login, $session, $permission)
	{
		$principal_id = $this->getPrincipalId($login, $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'principal-id' => $principal_id,
			'acl-id' => $meeting_id,
			'session' => $session,
			'permission-id' => $permission
		));
		
		$ctx = $this->proxy(array());
		$result = file_get_contents($url, false, $ctx);
		$xml = simplexml_load_string($result);
		if ($xml->status['code'] == 'ok') {
			return true;
		}
	}

	/**
	 * Deletes a participant in the meeting
	 *
	 * @param String $meeting_id
	 * @param String $login
	 * @param String $session
	 * @return boolean Returns true if everything is ok
	 */
	public function deleteMeetingParticipant($meeting_id, $login, $session)
	{
		$principal_id = $this->getPrincipalId($login, $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'permissions-update',
			'acl-id' => $meeting_id,
			'session' => $session,
			'principal-id' => $principal_id,
			'permission-id' => 'remove'
		));
		$xml = simplexml_load_file($url);
		
		return ($xml->status['code'] == "ok" ? true : false);
	}

	/**
	 * Returns all meeting ids on the Adobe Connect server
	 *
	 * @param String $session
	 * @return array
	 */
	public function getMeetingsId($session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'report-bulk-objects',
			'filter-type' => 'meeting',
			'session' => $session
		));
		$xml = simplexml_load_file($url);
		
		foreach ($xml->{'report-bulk-objects'}->row as $meeting) {
			if ($meeting->{'date-end'} != '') {
				$result[] = (string) $meeting['sco-id'];
			}
		}
		
		return $result;
	}

	/**
	 * Returns user id
	 *
	 * @param String $login
	 * @param String $session
	 * @return String
	 */
	public function getPrincipalId($login, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'principal-list',
			'filter-login' => $login,
			'session' => $session
		));
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->{'principal-list'}->principal['principal-id'];
		} else {
			$this->logger->write('AdobeConnect getPrincipalId Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getPrincipalId Response: ' . $xml->asXML());
			}
			
			return NULL;
		}
	}

	/**
	 * Check whether a user is host in a meeting.
	 *
	 * @param String $login
	 * @param String $meeting
	 * @param String $session
	 * @return boolean
	 */
	public function isParticipant($login, $meeting, $session)
	{
		$p_id = $this->getPrincipalId($login, $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'permissions-info',
			'acl-id' => $meeting,
			'filter-principal-id' => $p_id,
			'session' => $session
		));
		$xml = simplexml_load_file($url);
		
		if (in_array((string) $xml->permissions->principal['permission-id'], array(
			'host',
			'mini-host',
			'view'
		))) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Check the Permissions of a user on a meeting
	 *
	 * @param
	 *        	String Adobe Login Name
	 * @param
	 *        	Int Meeting SCO-id
	 * @param
	 *        	Sting Session Identifier
	 * @return String User Name or NULL if something is wrong
	 */
	public function getMeetingPermission($adobe_login_name, $meeting, $session)
	{
		$p_id = $this->getPrincipalId($adobe_login_name, $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'permissions-info',
			'acl-id' => $meeting,
			'filter-principal-id' => $p_id,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		if ($xml && ($perm = $xml->permissions->principal['permission-id']) != "") {
			return (string) $perm;
		} else {
			$this->logger->write('AdobeConnect getBreezeSession Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getBreezeSession Response: ' . $xml->asXML());
			}
			
			return NULL;
		}
	}

	public function getPermissionId($meeting, $session)
	{
		$url2 = $this->getApiUrl(array(
			'action' => 'permissions-info',
			'acl-id' => $meeting,
			'principal-id' => 'public-access',
			'session' => $session
		));
		
		$xml2 = simplexml_load_file($url2);
		$permission_id = (string) $xml2->permission['permission-id'];
		
		// ADOBE CONNECT API BUG!! if access-level is "PROTECTED" the api does not return a proper permission_id. it returns an empty string
		if (! $permission_id) {
			return 'remove';
		} else {
			return $permission_id;
		}
	}

	public function getActiveUsers($a_sco_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'report-bulk-consolidated-transactions',
			'filter-type' => 'meeting',
			'session' => $session,
			'filter-sco-id' => $a_sco_id
		));
		
		$xml = simplexml_load_file($url);
		
		if ($xml->status['code'] == "ok") {
			foreach ($xml->{'report-bulk-consolidated-transactions'}->row as $meeting) {
				if ($meeting->{'status'} == 'in-progress') {
					$result[] = (string) $meeting->{'user-name'};
				}
			}
			return $result;
		} else {
			$this->logger->write('AdobeConnect getActiveUsers Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getActiveUsers Response: ' . $xml->asXML());
			}
			
			return array();
		}
	}

	/**
	 *
	 * Generates an url encoded string for api calls
	 *
	 * @param array $params
	 *        	Query parameters passed as an array structure
	 * @return string
	 * @access private
	 *        
	 */
	protected function getApiUrl($params)
	{
		$server = $this->server;
		if (substr($server, - 1) == '/') {
			$server = substr($server, 0, - 1);
		}
		
		if (! $this->port || $this->port == '8080') {
			$api_url = $server;
		} else {
			$api_url = $server . ':' . $this->port;
		}
		
		$api_url .= '/api/xml?' . http_build_query($params);
		
		return $api_url;
	}

	/**
	 *
	 * Performs a cached call based on a static cache.
	 *
	 * @param string $url
	 * @return SimpleXMLElement
	 */
	protected function getCachedSessionCall($url)
	{
		$hash = $url;
		if (isset(self::$scocontent_cache[$hash])) {
			return self::$scocontent_cache[$hash];
		}
		
		$xml = simplexml_load_file($url);
		
		self::$scocontent_cache[$hash] = $xml;
		
		return $xml;
	}

	private function useHTTPHeaderAuthentification($user)
	{
		$x_user_id = $this->getXUserId();
		
		$headers = join("\r\n", array(
			'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Encoding: gzip,deflate',
			'Cache-Control: max-age=0',
			'Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7',
			'Keep-Alive: 300',
			'Connection: keep-alive',
			$x_user_id . ': ' . $user
		));
		
		$opts = array(
			'http' => array(
				'method' => 'GET',
				'header' => $headers
			),
			'https' => array(
				'method' => 'GET',
				'header' => $headers
			)
		);
		
		$url = $this->getApiUrl(array(
			'action' => 'login',
			'external-auth' => 'use'
		));
		
		$ctx = $this->proxy($opts);
		$result = file_get_contents($url, false, $ctx);
		
		$xml = simplexml_load_string($result);
		if ($xml instanceof SimpleXMLElement && $xml->status['code'] == 'ok') {
			foreach ($http_response_header as $header) {
				if (strpos(strtolower($header), 'set-cookie') === 0) {
					$matches = array();
					preg_match('/set-cookie\\s*:\\s*breezesession=([a-z0-9]+);?/i', $header, $matches);
					
					if ($matches[1]) {
						return $matches[1];
					}
				}
			}
		}
		return false;
	}

	private function usePasswordAuthentication($user)
	{
		$this->logger->write("Adobe Connect " . __METHOD__ . ": Entered frontend user authentication.");
		
		if (! ($pwd = $ilUser->getPref('xavc_pwd'))) {
			if ($this->changeUserPassword($user, $pwd = md5(uniqid(microtime(), true)))) {
				$ilUser->setPref('xavc_pwd', $pwd);
				$ilUser->writePrefs();
			} else {
				$this->logger->write("Adobe Connect " . __METHOD__ . ": No password found in user preferences (Id: " . $ilUser->getId() . " | " . $ilUser->getLogin() . "). Could not change password for user '{$user}' on Adobe Connect server.");
				return NULL;
			}
		}
		
		$session = $this->getBreezeSession(false);
		if ($this->login($user, $pwd, $session)) {
			$this->logger->write("Adobe Connect " . __METHOD__ . ": Successfully authenticated session (Id: " . $ilUser->getId() . " | " . $ilUser->getLogin() . ").");
			return $session;
		} else {
			$this->logger->write("Adobe Connect " . __METHOD__ . ": First login attempt not permitted (Id: " . $ilUser->getId() . " | " . $ilUser->getLogin() . "). Will change random password for user '{$user}' on Adobe Connect server.");
			if ($this->changeUserPassword($user, $pwd = md5(uniqid(microtime(), true)))) {
				$ilUser->setPref('xavc_pwd', $pwd);
				$ilUser->writePrefs();
				
				if ($this->login($user, $pwd, $session)) {
					$this->logger->write("Adobe Connect " . __METHOD__ . ": Successfully authenticated session (Id: " . $ilUser->getId() . " | " . $ilUser->getLogin() . ").");
					return $session;
				} else {
					$this->logger->write("Adobe Connect " . __METHOD__ . ": Second login attempt not permitted (Id: " . $ilUser->getId() . " | " . $ilUser->getLogin() . "). Password changed for user '{$user}' on Adobe Connect server.");
				}
			} else {
				$this->logger->write("Adobe Connect " . __METHOD__ . ": Login not permitted (Id: " . $ilUser->getId() . " | " . $ilUser->getLogin() . "). Could not change password for user '{$user}' on Adobe Connect server.");
			}
			return NULL;
		}
	}

	/**
	 * Gets meeting or content modification date
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting or content modification date, or NULL if something is wrong
	 */
	public function getDateEnd($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->scos->sco->{'date-end'};
		} else {
			$this->logger->write('AdobeConnect getDateEnd Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getDateEnd Response: ' . $xml->asXML());
			}
			
			return NULL;
		}
	}

	public function lookupUserFolderId($login, $session)
	{
		$umf_id = $this->getShortcuts('user-meetings', $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $umf_id,
			'filter-name' => $login,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		$id = NULL;
		if ($xml instanceof SimpleXMLElement && 'ok' == (string) $xml->status['code']) {
			foreach ($xml->scos->sco as $sco) {
				if ($sco['type'] == 'folder') {
					$id = (string) $sco['sco-id'];
				}
			}
		}
		
		return $id;
	}

	public function createUserFolder($login, $session)
	{
		$umf_id = $this->getShortcuts('user-meetings', $session);
		
		$url = $this->getApiUrl(array(
			'action' => 'sco-update',
			'folder-id' => $umf_id,
			'type' => 'folder',
			'name' => $login,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		$id = NULL;
		
		if ($xml->status['code'] == "ok") {
			return (string) $xml->sco['sco-id'];
		} else {
			$this->logger->write('AdobeConnect createUserFolder Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect createUserFolder Response: ' . $xml->asXML());
			}
		}
		return NULL;
	}

	public function getScosByFolderId($folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'session' => $session
		));
		
		$xml = simplexml_load_file($url);
		
		$result = array();
		if ($xml instanceof SimpleXMLElement && 'ok' == (string) $xml->status['code']) {
			foreach ($xml->scos->sco as $meeting) {
				if ($meeting['type'] == 'meeting') {
					$id = (string) $meeting['sco-id'];
					
					$result[(string) $meeting['sco-id']]['sco_id'] = (string) $meeting['sco-id'];
					$result[(string) $meeting['sco-id']]['sco_name'] = (string) $meeting->{'name'};
					$result[(string) $meeting['sco-id']]['description'] = (string) $meeting->{'description'};
					$result[(string) $meeting['sco-id']]['sco_url'] = (string) $meeting->{'url'};
					$result[(string) $meeting['sco-id']]['date_end'] = (string) $meeting->{'date-end'};
				}
			}
		}
		return $result;
	}

	public function getScoData($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		$data = array();
		if ($xml->status['code'] == "ok") {
			$data['start_date'] = (string) $xml->scos->sco->{'date-begin'};
			$data['end_date'] = (string) $xml->scos->sco->{'date-end'};
		} else {
			$this->logger->write('AdobeConnect getStartDate Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getStartDate Response: ' . $xml->asXML());
			}
			
			return NULL;
		}
		
		return $data;
	}

	/**
	 * lookup content-attribute 'icon'
	 * if icon == 'archive' the content is a record
	 */
	public function getContentIconAttribute($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'sco-contents',
			'sco-id' => $folder_id,
			'filter-sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		$icon = '';
		
		if ($xml->status['code'] == "ok") {
			foreach ($xml->scos->sco as $sco) {
				$icon = (string) $sco['icon'];
			}
		}
		return $icon;
	}

	/**
	 *
	 * @param
	 *        	string adobe id of the meeting room
	 * @param
	 *        	string session
	 *        	
	 * @return int number of pax currently in the room or NULL if something went wrong
	 */
	public function getCurrentPax($sco_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action' => 'report-meeting-sessions',
			'sco-id' => $sco_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		$sessions = $xml->{'report-meeting-sessions'}->row;
		$asset_id = (string) $sessions[count($sessions) - 1]['asset-id'];
		
		$url = $this->getApiUrl(array(
			'action' => 'report-meeting-session-users',
			'sco-id' => $sco_id,
			'asset-id' => $asset_id,
			'session' => $session
		));
		
		$xml = $this->getCachedSessionCall($url);
		
		if ($xml->status['code'] == "ok") {
			$users = $xml->{'report-meeting-session-users'}->row;
			$total_users = count($users);
			$current_users = 0;
			$principal_ids = [];
			foreach ($users as $user) {
				if (in_array((string) $user['principal-id'], $principal_ids)) {
					continue;
				} else if (isset($user->{'date-end'})) {
					if (time() - strtotime($user->{'date-end'}) < 300) {
						array_push($principal_ids, (string) $user['principal-id']);
					}
				} else {
					array_push($principal_ids, (string) $user['principal-id']);
				}
			}
			
			return $principal_ids;
		} else {
			$this->logger->write('AdobeConnect getCurrentPax Request: ' . $url);
			if ($xml) {
				$this->logger->write('AdobeConnect getCurrentPax Response: ' . $xml->asXML());
			}
		}
		return NULL;
	}

	/**
	 *
	 * @param
	 *        	$pluginObj
	 * @return array
	 */
	public function getTemplates($pluginObj)
	{
		$txt_shared_meeting_templates = $pluginObj->txt('shared_meeting_templates');
		$txt_my_meeting_templates = $pluginObj->txt('my_meeting_templates');
		
		$session = $this->getAdminSession();
		$url_1 = $this->getApiUrl(array(
			'action' => 'sco-shortcuts',
			'session' => $session
		));
		
		$xml = simplexml_load_file($url_1);
		$templates = array();
		
		foreach ($xml->shortcuts->sco as $folder) {
			if ((ilAdobeConnectServer::getSetting('user_assignment_mode') != ilAdobeConnectServer::ASSIGN_USER_SWITCH && $folder['type'] == 'shared-meeting-templates') || $folder['type'] == 'my-meeting-templates') {
				$sco_id = (string) $folder['sco-id'];
				$txt_folder_name = $folder['type'] == 'shared-meeting-templates' ? $txt_shared_meeting_templates : $txt_my_meeting_templates;
				$url_2 = $this->getApiUrl(array(
					'action' => 'sco-contents',
					'sco-id' => $sco_id,
					'session' => $session
				
				));
				$xml_2 = simplexml_load_file($url_2);
				
				foreach ($xml_2->scos->sco as $sco) {
					$template_sco_id = (string) $sco['sco-id'];
					$templates[$template_sco_id] = (string) $sco->{'name'} . ' (' . $txt_folder_name . ')';
				}
			}
		}
		asort($templates);
		return $templates;
	}

	/**
	 *
	 * @param
	 *        	$ctx
	 * @return stream context || null
	 */
	protected function proxy($ctx = null)
	{
		require_once ('Services/Http/classes/class.ilProxySettings.php');
		
		if (ilProxySettings::_getInstance()->isActive()) {
			
			$proxyHost = ilProxySettings::_getInstance()->getHost();
			$proxyPort = ilProxySettings::_getInstance()->getPort();
			$proxyURL = 'tcp://' . $proxyPort != '' ? $proxyHost . ':' . $proxyPort : $proxyHost;
			
			$proxySingleContext = array(
				'proxy' => $proxyURL,
				'request_fulluri' => true
			);
			
			$proxyContext = array(
				'http' => $proxySingleContext,
				'https' => $proxySingleContext
			);
			
			if ($ctx == null) {
				
				$proxyStreamContext = stream_context_get_default($proxyContext);
				libxml_set_streams_context($proxyStreamContext);
			} elseif (is_array($ctx)) {
				
				$mergedProxyContext = array_merge_recursive($proxyContext, $ctx);
				
				return stream_context_create($mergedProxyContext);
			}
		} elseif (is_array($ctx) && count($ctx)) {
			return stream_context_create($ctx);
		}
		
		return null;
	}
}
