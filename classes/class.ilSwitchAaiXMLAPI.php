<?php
include_once dirname(__FILE__) . '/class.ilAdobeConnectXMLAPI.php';

/**
 * Class ilSwitchAaiXMLAPI
 *
 * In case of SWITCHaai we so everything with the session of the logged in User in ILIAS!
 * Except add a user to a meeting in case of he access a meeting himself -> updateMeetingParticipantByTechnicalUser()
 *
 *
 * @author Nadia Matuschek <nmatuschek@databay.de>
 * @author Martin Studer <ms@studer-raimann.ch>
 */
class ilSwitchAaiXMLAPI extends ilAdobeConnectXMLAPI
{

	protected static $technical_user_session = null;
	
	/**
	 *
	 * @var ilLogger $ilLog
	 */
	private $log;
	
	public function __construct() {
		parent::__construct();
		
		global $DIC;
		$this->log = $DIC->logger();
	}

	/**
	 * Logs in user on Adobe Connect server. This is done by redirection to the cave server.
	 * @ilObjUser $ilUser
	 * @param String $user
	 * @param String $pass
	 * @param String $session
	 * @return String       Session id
	 */
	public function externalLogin($user = null, $pass = null, $session = null )
	{
		global $DIC;
		
		/**
		 * @var ilObjUser $ilUser
		 */
		$ilUser = $DIC->user();

		self::$breeze_session = null;

		//if there is already a session don't create a new session
		if($_SESSION['breezesession']) {
			self::$breeze_session = $_SESSION['breezesession'];
			return $_SESSION['breezesession'];
		}

		//SWITCH aai user logins
		if(!$_GET['breezesession'])
		{
			//header() redirects do NOT necessarily stop the script, so we put an exit after it
			header('Location: ' . ilAdobeConnectServer::getSetting('cave') .'?back=https://'.$_SERVER['HTTP_HOST'].urlencode($_SERVER['REQUEST_URI']).'&request_session=true');
			exit;
		}

		self::$breeze_session = $_GET['breezesession'];
		//cache the Session in a cookie
		$_SESSION['breezesession'] = $_GET['breezesession'];
		self::$loginsession_cache[$_SESSION['breezesession']] = true;
		return $_SESSION['breezesession'];
	}

	/**
	 * @inheritdoc
	 */
	public function getBreezeSession($useCache = true)
	{
		//The BreezeSession is in the SWITCH-Case the Session of the user
		return $this->externalLogin();
	}
	
	/**
	 * Check if user already has a breeze-session
	 * 
	 * @return String Session id or false if there is no session
	 */
	public function checkBreezeSession()
	{
		if (!($breezeSession = $_SESSION['breezesession'])) {
			$breezeSession = "false";
		}
		
		return $breezeSession;
	}

	/**
	 *  Get's the AdminSession
	 *
	 *  With SWITCHaai we use the in ILIAS loggt in user for this purpose
	 *
	 * @return String breeze-Session
	 */
	public function getAdminSession()
	{
		//The AdminSession is in the SWITCH-Case the Session of the user
		return $this->externalLogin();
	}

    /**
     *  Logs in user on the Adobe Connect server.
     *
     *  With SWITCHaai we use this login only to log in the technical user!
     *  
     *  Parameters are only passed for compliance
     *
     * @return string $technical_user_session
     */
	public function login($user='', $pass='', $session='')
	{
		global $DIC;
		
		/**
		 * 
		 * @var ilLanguage $lng
		 */
		$lng = $DIC->language();

		if(null !== self::$technical_user_session)
		{
			return self::$technical_user_session;
		}
		else if($session != '' && isset(self::$loginsession_cache[$session]))
		{
			self::$technical_user_session = self::$loginsession_cache[$session];
			return self::$technical_user_session;
		}

		$instance = ilAdobeConnectServer::_getInstance();

        $params['action'] = 'login';
        $params['login'] = $instance->getLogin();
        $params['password'] = $instance->getPasswd();

        $api_url = self::getApiUrl($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $output = curl_exec($ch);
        $curlHeaderSize=curl_getinfo($ch,CURLINFO_HEADER_SIZE);

        //Get the Header part from the output
        $ResponseHeader = mb_substr($output, 0, $curlHeaderSize);

        //get the breezeSession
        preg_match_all('|BREEZESESSION=(.*);|U', $ResponseHeader, $content);
		self::$technical_user_session = implode(';', $content[1]);

		return self::$technical_user_session;
    }

	/**
	 * Gets meeting or content URL
	 *
	 * With SWITCHaai it's not possible to get the meeting url by folder_id! Because we have no permissions to do this!
	 *
	 * @param String $sco_id          Meeting or content id
	 * @param String $folder_id       Parent folder id
	 * @param String $session         Session id
	 * @param String $type            Used for SWITCHaai meeting|content|...
	 * @return String                 Meeting or content URL, or NULL if something is wrong
	 */
    public function getURL($sco_id, $folder_id, $session, $type = 'default')
	{
		switch($type) {
			case 'meeting':
				$url = $this->getApiUrl(array(
					'action'  => 'report-my-meetings',
					'session' => $session
				));

				$xml = $this->getCachedSessionCall($url);

				if($xml->status['code'] == "ok")
				{
					foreach($xml->{'my-meetings'}->meeting as $meeting)
					{
						if($meeting['sco-id'] == $sco_id)
						{
							return (string)$meeting->{'url-path'};
						}
					}
				}
				$this->log->write('AdobeConnect getURL Request: ' . $url);
				$this->log->write('AdobeConnect getURL Response: ' . $xml->asXML());

				return NULL;
			break;
			default:
				return parent::getURL($sco_id, $folder_id, $session);
			break;
		}
	}

	/**
	 *  Gets meeting start date
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting start date, or NULL if something is wrong
	 */
	public function getStartDate($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action'  => 'report-my-meetings',
			'session' => $session
		));

		$xml = $this->getCachedSessionCall($url);

		if($xml->status['code'] == "ok")
		{
			foreach($xml->{'my-meetings'}->meeting as $meeting)
			{
				if($meeting['sco-id'] == $sco_id)
				{
					return (string)$meeting->{'date-begin'};
				}

			}
		}
		else
		{
			$this->log->write('AdobeConnect getStartDate Request: '.$url);
			$this->log->write('AdobeConnect getStartDate Response: '.$xml->asXML());

			return NULL;
		}
	}

	/**
	 *  Gets meeting end date
	 *
	 * @param String $sco_id
	 * @param String $folder_id
	 * @param String $session
	 * @return String Meeting start date, or NULL if something is wrong
	 */
	public function getEndDate($sco_id, $folder_id, $session)
	{
		$url = $this->getApiUrl(array(
			'action'  => 'report-my-meetings',
			'session' => $session
		));

		$xml = $this->getCachedSessionCall($url);

		if($xml->status['code'] == "ok")
		{
			foreach($xml->{'my-meetings'}->meeting as $meeting)
			{
				if($meeting['sco-id'] == $sco_id)
				{
					return (string)$meeting->{'date-end'};
				}

			}
		}
		else
		{
			$this->log->write('AdobeConnect getStartDate Request: '.$url);
			$this->log->write('AdobeConnect getStartDate Response: '.$xml->asXML());

			return NULL;
		}
	}
	
	/**
	 * Get the User Name of the currently logged in Principal
	 *
	 * @return  String        User Name or NULL if something is wrong
	 */
	public function getCurrentUserSwitchUserName()
	{
		$session = $this->getBreezeSession();
		
		$url = $this->getApiUrl(array('action' => 'common-info', 'session' => $session));
		
		$ctx = stream_context_create(array(
				'http' => array('timeout' => 4),
				'https' => array('timeout' => 4)
		));
		
		$xml_string = file_get_contents($url, false, $ctx);
		$xml = simplexml_load_string($xml_string);
		
		if($xml && ($principal = $xml->common->user->login) != "")
		{
			return (string)$principal;
		}
		else
		{
			$this->log->write('AdobeConnect getBreezeSession Request: '.$url);
			if($xml)
			{
				$this->log->write('AdobeConnect getBreezeSession Response: '.$xml->asXML());
			}
			return NULL;
		}
	}

	/**
	 * @param $meeting_id
	 * @param $login
	 * @param $session
	 * @param $permission
	 *
	 * @return bool
	 */
	public function updateMeetingParticipantByTechnicalUser($meeting_id, $login, $session, $permission)
	{
		$principal_id = $this->getPrincipalId($login, $session);

		$technical_user_session = $this->login();

		$url = $this->getApiUrl(array(
			'action' 		=> 'permissions-update',
			'principal-id' => $principal_id,
			'acl-id' 		=> $meeting_id,
			'session'		=> $technical_user_session,
			'permission-id'=> $permission
		));

		$xml = simplexml_load_file($url);
		if($xml->status['code'] == 'ok')
		{
			return true;
		}
	}
}
