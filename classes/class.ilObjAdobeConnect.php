<?php
/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
include_once './Services/Repository/classes/class.ilObjectPlugin.php';
include_once './Services/Calendar/classes/class.ilDateTime.php';

/**
 * Main application class for Adobe Connect repository object
 *
 * @author Nadia Matuschek <nmatuschek@databay.de>
 */
class ilObjAdobeConnect extends ilObjectPlugin
{

	const ACCESS_LEVEL_PRIVATE = 'denied';

	// no guests !
	const ACCESS_LEVEL_PROTECTED = 'remove';

	const ACCESS_LEVEL_PUBLIC = 'view-hidden';

	/**
	 * default role id, User-Role by default
	 *
	 * @var int
	 */
	const RBAC_DEFAULT_ROLE_ID = 4;

	/**
	 * guest role id
	 *
	 * @var int
	 */
	const RBAC_GUEST_ROLE_ID = 5;

	/**
	 * Meeting id
	 *
	 * @var String
	 */
	private $sco_id;

	/**
	 * Meeting start date
	 *
	 * @var ilDateTime
	 */
	private $start_date;

	/**
	 * Meeting duration
	 *
	 * @var array
	 */
	private $duration;

	/**
	 * Meeting instructions
	 *
	 * @var String
	 */
	private $instructions = NULL;

	/**
	 * Meeting language
	 *
	 * @var String
	 */
	private $meeting_lang = Null;

	/**
	 * Max Participants
	 *
	 * @var String
	 */
	private $max_pax = 0;

	/**
	 * Current Participants
	 *
	 * @var String
	 */
	private $current_pax = 0;

	/**
	 *
	 * @var null
	 *
	 */
	private $contact_info = NULL;

	/**
	 *
	 * @var int
	 */
	private $permanent_room = 0;

	/**
	 * *
	 *
	 * @var string
	 */
	private $access_level = self::ACCESS_LEVEL_PROTECTED;

	/**
	 *
	 * @var int
	 */
	private $read_contents = 0;

	/**
	 *
	 * @var int
	 */
	private $read_records = 0;

	/**
	 *
	 * @var int
	 */
	private $folder_id = 0;

	/**
	 * Meeting URL
	 *
	 * @var String
	 */
	private $url;

	/**
	 * Meeting contents
	 *
	 * @var ilAdobeConnectContents
	 */
	private $contents;

	/**
	 * Adobe Connect admin login
	 *
	 * @var String
	 */
	private $adminLogin;

	/**
	 * Adobe Connect admin password
	 *
	 * @var String
	 */
	private $adminPass;

	/**
	 *
	 * @var null|void
	 */
	public $externalLogin;

	/**
	 *
	 * @var ilAdobeConnectDfnXMLAPI|ilAdobeConnectXMLAPI|ilSwitchAaiXMLAPI
	 */
	public $xmlApi;

	/**
	 *
	 * @var
	 */
	private $permission;

	/**
	 *
	 * @var null
	 */
	public $assignment_mode = null;

	/**
	 *
	 * @var null
	 */
	public $end_date = null;

	/**
	 *
	 * @var null
	 */
	public $pluginObj = null;

	/**
	 *
	 * @var null
	 */
	public $participants = null;

	/**
	 *
	 * @var bool
	 */
	public $use_meeting_template = false;

	/**
	 *
	 * @var $ctrl ilCtrl
	 */
	public $ctrl;

	/**
	 *
	 * @var $lng ilLanguage
	 */
	public $lng;

	/**
	 *
	 * @var $user ilObjUser
	 */
	public $user;

	/**
	 * Constructor
	 *
	 * @access public
	 */
	public function __construct($a_ref_id = 0)
	{
		/**
		 *
		 * @var $DIC ilDIC
		 */
		global $DIC;
		
		parent::__construct($a_ref_id);
		
		$this->ctrl = $DIC->ctrl();
		$this->lng = $DIC->language();
		$this->user = $DIC->user();
		$this->rbacreview = $DIC->rbac()->review();
		$this->rbacadmin = $DIC->rbac()->admin();
		
		$this->ref_id = $a_ref_id;
		$this->pluginObj = ilPlugin::getPluginObject('Services', 'Repository', 'robj', 'AdobeConnect');
		
		if (! $this->ctrl->isAsynch()) {
			$this->pluginObj->includeClass('class.ilAdobeConnectContents.php');
			$this->contents = new ilAdobeConnectContents();
		}
		
		$instance = ilAdobeConnectServer::_getInstance();
		$this->adminLogin = $instance->getLogin();
		$this->adminPass = $instance->getPasswd();
		$this->externalLogin = $this->checkExternalUser();
		
		$this->xmlApi = ilXMLApiFactory::getApiByAuthMode();
	}

	/**
	 */
	private function initParticipantsObject()
	{
		if ($this->getRefId() > 0) {
			$this->pluginObj->includeClass('class.ilAdobeConnectContainerParticipants.php');
			
			$parent_ref = $this->tree->checkForParentType($this->getRefId(), 'grp');
			if (! $parent_ref) {
				$parent_ref = $this->tree->checkForParentType($this->getRefId(), 'crs');
			}
			
			$object_id = ilObject::_lookupObjectId($parent_ref);
			$this->participants = ilAdobeConnectContainerParticipants::getInstanceByObjId($object_id);
		}
	}

	/**
	 *
	 * @return ilAdobeConnectContainerParticipants|null
	 */
	public function getParticipantsObject()
	{
		if (! $this->participants instanceof ilAdobeConnectContainerParticipants) {
			$this->initParticipantsObject();
		}
		
		return $this->participants;
	}

	/**
	 *
	 * @param int $user_id
	 * @return null|void
	 */
	public function checkExternalUser($user_id = 0)
	{
		if (! (isset($user_id) && $user_id > 0)) {
			$user_id = $this->user->getId();
		}
		
		// check if there is a xavc-login already saved in ilias-db
		$this->pluginObj->includeClass('class.ilXAVCMembers.php');
		$tmp_xavc_login = ilXAVCMembers::_lookupXAVCLogin($user_id);
		
		if (! $tmp_xavc_login) {
			$this->pluginObj->includeClass('class.ilAdobeConnectUserUtil.php');
			$xavc_login = ilAdobeConnectUserUtil::generateXavcLoginName($user_id);
			
			$externalLogin = ilXAVCMembers::addXAVCUser($user_id, $xavc_login);
		} else {
			// get saved login-data
			$externalLogin = $tmp_xavc_login;
		}
		return $externalLogin;
	}

	/**
	 * Get type.
	 */
	public final function initType()
	{
		$this->setType("xavc");
	}

	/**
	 * Rollback function for creation workflow
	 *
	 * @access private
	 */
	private function creationRollback()
	{
		$this->delete();
	}

	public function doCloneObject($new_obj, $a_target_id, $a_copy_id = null)
	{
		parent::doCloneObject($new_obj, $a_target_id, $a_copy_id); // TODO: Change the autogenerated stub
	}

	/**
	 * Create plugin specific data
	 *
	 * @access public
	 */
	public function doCreate()
	{
		$cmdClass = $this->ctrl->getCmdClass();
		$cmd = $this->ctrl->getCmd();
		
		if ($cmdClass == 'ilobjectcopygui') {
			$clone_ref_id = $this->getRefId();
			
			$now = new ilDateTime(time(), IL_CAL_UNIX);
			$this->start_date = new ilDateTime($now->getUnixTime() - 7200, IL_CAL_UNIX);
			$this->duration = array(
				'hours' => 1,
				'minutes' => 0
			);
			
			$this->publishCreationAC();
			
			return;
		} else {
			
			if (isset($_POST['tpl_id']) && (int) $_POST['tpl_id'] > 0) {
				$tpl_id = (int) $_POST['tpl_id'];
			} else {
				throw new ilException('no_template_id_given');
			}
			
			include_once "Services/Administration/classes/class.ilSettingsTemplate.php";
			$templates = ilSettingsTemplate::getAllSettingsTemplates("xavc");
			
			foreach ($templates as $template) {
				if ((int) $template['id'] == $tpl_id) {
					$template_settings = array();
					if ($template['id']) {
						$objTemplate = new ilSettingsTemplate($template['id']);
						$template_settings = $objTemplate->getSettings();
					}
				}
			}
			
			// reuse existing ac-room
			if (isset($_POST['creation_type']) && $_POST['creation_type'] == 'existing_vc' && $template_settings['reuse_existing_rooms']['hide'] == '0') {
				// 1. the sco-id will be assigned to this new ilias object
				$sco_id = (int) $_POST['available_rooms'];
				try {
					$this->useExistingVC($this->getId(), $sco_id);
				} catch (ilException $e) {
					$this->creationRollback();
					throw new ilException($this->txt($e->getMessage()));
				}
				return;
			}
			
			if (strlen($_POST['instructions']) > 0)
				$post_instructions = (string) $_POST['instructions'];
			else if (strlen($_POST['instructions_2']) > 0)
				$post_instructions = (string) $_POST['instructions_2'];
			else if (strlen($_POST['instructions_3']) > 0)
				$post_instructions = (string) $_POST['instructions_3'];
			
			if (strlen($_POST['contact_info']) > 0)
				$post_contact = (string) $_POST['contact_info'];
			else if (strlen($_POST['contact_info_2']) > 0)
				$post_contact = (string) $_POST['contact_info_2'];
			else if (strlen($_POST['contact_info_3']) > 0)
				$post_contact = (string) $_POST['contact_info_3'];
			
			$this->setInstructions($post_instructions);
			$this->setContactInfo($post_contact);
			
			if (isset($_POST['time_type_selection']) && $_POST['time_type_selection'] == 'permanent_room') {
				$this->setPermanentRoom(1);
			} else if (! isset($_POST['time_type_selection']) && ilAdobeConnectServer::getSetting('default_perm_room') == 1) {
				$this->setPermanentRoom(1);
			} else {
				$this->setPermanentRoom(0);
			}
			
			if (isset($_POST['access_level'])) {
				$this->setPermission($_POST['access_level']);
			} else {
				$this->setPermission(ilObjAdobeConnect::ACCESS_LEVEL_PROTECTED);
			}
			
			if (strlen($_POST['meeting_lang']) > 0) {
				$this->setMeetingLang($_POST['meeting_lang']);
			} else if ($lang = ilAdobeConnectServer::getDefaultLang()) {
				$this->setMeetingLang($lang);
			}
			
			if (strlen($_POST['max_pax']) > 0) {
				$this->setMaxPax($_POST['max_pax']);
			} else if (($max_pax = ilAdobeConnectServer::getSetting('max_pax')) > 0) {
				$this->setMaxPax($max_pax);
			}
			
			$this->pluginObj->includeClass('class.ilXAVCPermissions.php');
			$this->setReadContents(ilXAVCPermissions::lookupPermission(AdobeConnectPermissions::PERM_READ_CONTENTS, 'view'));
			$this->setReadRecords(ilXAVCPermissions::lookupPermission(AdobeConnectPermissions::PERM_READ_RECORDS, 'view'));
			
			$this->externalLogin = $this->checkExternalUser();
			
			$folder_id = $this->getFolderIdByLogin($this->externalLogin);
			
			$this->setFolderId($folder_id);
		}
		
		try {
			if (isset($_POST['start_date']) && is_string($_POST['start_date']) && strlen($_POST['start_date']) > 0 && $template_settings['start_date']['hide'] == '0') {
				$this->start_date = new ilDateTime($_POST['start_date'], IL_CAL_DATETIME);
			} else if (isset($_POST['start_date']) && is_array($_POST['start_date']) && $template_settings['start_date']['hide'] == '0') {
				$this->start_date = new ilDateTime($_POST['start_date']['date'] . ' ' . $_POST['start_date']['time'], IL_CAL_DATETIME);
			} else {
				$this->start_date = new ilDateTime(time() + 120, IL_CAL_UNIX);
			}
			
			// duration
			if (isset($_POST['duration']['hh']) && isset($_POST['duration']['mm']) && ($_POST['duration']['hh'] > 0 || $_POST['duration']['mm'] > 0) && $template_settings['duration']['hide'] == '0') {
				$this->duration = array(
					'hours' => $_POST['duration']['hh'],
					'minutes' => $_POST['duration']['mm']
				);
			} else {
				$this->duration = array(
					'hours' => (int) $template_settings['duration']['value'],
					'minutes' => 0
				);
			}
			
			// end_date
			$this->end_date = $this->getEnddate();
			
			$concurrent_vc = count($this->checkConcurrentMeetingDates());
			$max_rep_obj_vc = ilAdobeConnectServer::getSetting('ac_interface_objects');
			if ((int) $max_rep_obj_vc > 0 && $concurrent_vc >= $max_rep_obj_vc) {
				throw new ilException('xavc_reached_number_of_connections');
			}
			
			$this->setUseMeetingTemplate($_POST['use_meeting_template'] == '1' ? true : false);
			$this->publishCreationAC();
		} catch (ilException $e) {
			$this->creationRollback();
			throw new ilException($this->txt($e->getMessage()));
		}
	}

	public function useExistingVC($obj_id, $sco_id)
	{
		// receive breeze session
		$session = $this->getSession(false, true);
		if (! $session) {
			throw new ilException('xavc_connection_error');
		}
		
		// access check
		if (! $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			throw new ilException('xavc_authentication_error');
		}
		
		// receive folder id
		$this->externalLogin = $this->checkExternalUser();
		$folder_id = $this->getFolderIdByLogin($this->externalLogin);
		
		if (! $folder_id) {
			throw new ilException('xavc_folder_not_available');
		}
		
		if (! $sco_id) {
			throw new ilException('xavc_meeting_creation_error');
		}
		
		if (! $this->xmlApi->getName($sco_id, $folder_id, $session)) {
			throw new ilException('xavc_meeting_not_available');
		}
		
		if ($this->externalLogin == NULL) {
			throw new ilException('xavc_external_login_error');
		} else {
			$this->xmlApi->addUser($this->externalLogin, $this->user->getEmail(), $this->user->getPasswd(), $this->user->getFirstName(), $this->user->getLastName(), $session);
		}
		
		$this->xmlApi->updateMeetingParticipant($sco_id, $this->externalLogin, $session, 'host');
		
		$this->start_date = time();
		$this->end_date = $this->start_date + 7200;
		$this->meeting_lang = $this->xmlApi->getMeetingLang($sco_id, $session);
		
		$this->url = $this->xmlApi->getURL($sco_id, $folder_id, $session);
		
		$this->db->insert('rep_robj_xavc_data', array(
			'id' => array(
				'integer',
				$obj_id
			),
			'sco_id' => array(
				'integer',
				$sco_id
			),
			'start_date' => array(
				'integer',
				$this->start_date
			),
			'end_date' => array(
				'integer',
				$this->end_date
			),
			'folder_id' => array(
				'integer',
				$folder_id
			),
			'url_path' => array(
				'text',
				$this->url
			),
			'meeting_lang' => array(
				'text',
				$this->meeting_lang
			)
		));
	}

	/**
	 *
	 *
	 */
	public function publishCreationAC()
	{
		$owner_id = ilObject::_lookupOwner($this->getId());
		$ownerObj = new ilObjUser($owner_id);
		
		// receive breeze session
		$session = $this->getSession(false, true);
		if (! $session) {
			throw new ilException('xavc_connection_error');
		}
		
		// access check
		if (! $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			throw new ilException('xavc_authentication_error');
		}
		
		// receive folder id
		$this->externalLogin = $this->checkExternalUser($ownerObj->getId());
		
		$folder_id = $this->getFolderIdByLogin($this->externalLogin);
		
		if (! $this->getFolderId()) {
			throw new ilException('xavc_folder_not_available');
		}
		
		$obj_title_suffix_enabled = ilAdobeConnectServer::getSetting('obj_title_suffix');
		if ($obj_title_suffix_enabled) {
			$title = $title . '_' . CLIENT_ID . '_' . $obj_id;
		}
		
		$source_sco_id = 0;
		if ($this->isUseMeetingTemplate()) {
			$source_sco_id = ilAdobeConnectServer::getSetting('template_sco_id');
		}
		
		// create meeting room
		$arr_meeting = $this->xmlApi->addMeeting($this->getTitle(), $this->getDescription(), date('Y-m-d', $this->getStartDate()->getUnixTime()), date('H:i', $this->getStartDate()->getUnixTime()), date('Y-m-d', $this->getEndDate()->getUnixTime()), date('H:i', $this->getEndDate()->getUnixTime()), $this->getMeetingLang(), $this->getFolderId(), $session, $source_sco_id);
		
		$meeting_id = $arr_meeting['meeting_id'];
		$meeting_url = $arr_meeting['meeting_url'];
		
		if (! $meeting_id) {
			throw new ilException('xavc_meeting_creation_error');
		}
		
		if (ilAdobeConnectServer::getSetting('user_assignment_mode') != ilAdobeConnectServer::ASSIGN_USER_SWITCH) {
			// Normal Case (not SWITCH aai)
			
			if ($this->externalLogin == NULL) {
				throw new ilException('xavc_external_login_error');
			} else {
				$this->xmlApi->addUser($this->externalLogin, $ownerObj->getEmail(), $ownerObj->getPasswd(), $ownerObj->getFirstName(), $ownerObj->getLastName(), $session);
			}
			$this->xmlApi->updateMeetingParticipant($meeting_id, $this->externalLogin, $session, 'host');
		} else {
			// In the SWITCH aai case, every user already exists thanks to "cave"
			
			// Add ILIAS-user himself
			$this->xmlApi->addMeetingHost($meeting_id, $ownerObj->getEmail(), $session);
			// Add technical user
			$this->xmlApi->updateMeetingParticipant($meeting_id, ilAdobeConnectServer::getSetting('login'), $session, 'host');
		}
		
		$this->xmlApi->updatePermission($meeting_id, $session, $access_level);
		
		$this->db->insert('rep_robj_xavc_data', array(
			'id' => array(
				'integer',
				$this->getId()
			),
			'sco_id' => array(
				'integer',
				$meeting_id
			),
			'start_date' => array(
				'integer',
				$this->getStartDate()->getUnixTime()
			),
			'end_date' => array(
				'integer',
				$this->getEndDate()->getUnixTime()
			),
			'instructions' => array(
				'text',
				$this->getInstructions()
			),
			'contact_info' => array(
				'text',
				$this->getContactInfo()
			),
			'permanent_room' => array(
				'integer',
				(int) $this->getPermanentRoom()
			),
			'perm_read_contents' => array(
				'integer',
				(int) $this->getReadContents()
			),
			'perm_read_records' => array(
				'integer',
				(int) $this->getReadRecords()
			),
			'meeting_lang' => array(
				'text',
				$this->getMeetingLang()
			),
			'max_pax' => array(
				'text',
				$this->getMaxPax()
			),
			'folder_id' => array(
				'integer',
				$this->getFolderId()
			),
			'url_path' => array(
				'text',
				$meeting_url
			)
		));
	}

	/**
	 *
	 * @param integer $ref_id
	 *        	ref_id of ilias ac-object
	 * @param integer $sco_id
	 * @param array $member_ids
	 */
	public function addCrsGrpMembers($ref_id, $sco_id, $member_ids = null)
	{
		$oParticipants = $this->getParticipantsObject();
		if (count($oParticipants->getParticipants()) == 0) {
			return;
		}
		
		$role_map = ilAdobeConnectServer::getRoleMap();
		
		/** @var $oParticipants  ilGroupParticipants | ilCourseParticipants */
		$admins = $oParticipants->getAdmins();
		$tutors = $oParticipants->getTutors();
		$members = $oParticipants->getMembers();
		
		if (is_array($member_ids) && count($member_ids) > 0) {
			$all_participants = $member_ids;
			
			$admins = array_uintersect($member_ids, $admins, 'strcmp');
			$tutors = array_uintersect($member_ids, $tutors, 'strcmp');
			$members = array_uintersect($member_ids, $members, 'strcmp');
		} else {
			$all_participants = array_unique(array_merge($admins, $tutors, $members));
		}
		
		$this->pluginObj->includeClass('class.ilAdobeConnectRoles.php');
		$xavcRoles = new ilAdobeConnectRoles($ref_id);
		
		if (ilAdobeConnectServer::getSetting('user_assignment_mode') != ilAdobeConnectServer::ASSIGN_USER_SWITCH) {
			foreach ($all_participants as $user_id) {
				$this->pluginObj->includeClass('class.ilAdobeConnectUserUtil.php');
				
				// check if there is an adobe connect account at the ac-server
				$ilAdobeConnectUser = new ilAdobeConnectUserUtil($user_id);
				$ilAdobeConnectUser->ensureAccountExistance();
				
				// add to desktop
				if (ilAdobeConnectServer::getSetting('add_to_desktop') == 1) {
					ilObjUser::_addDesktopItem($user_id, $ref_id, 'xavc');
				}
			}
		}
		
		// receive breeze session
		$session = $this->getSession();
		
		$this->pluginObj->includeClass('class.ilXAVCMembers.php');
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			foreach ($admins as $user_id) {
				if ($user_id == $this->getOwner()) {
					continue;
				}
				
				$xavcRoles->addAdministratorRole($user_id);
				
				$is_member = ilXAVCMembers::_isMember($user_id, $ref_id);
				// local member table
				$xavcMemberObj = new ilXAVCMembers($ref_id, $user_id);
				
				$status = $role_map[$oParticipants->getType() . '_admin'];
				
				$xavcMemberObj->setStatus($status);
				$xavcMemberObj->setScoId($sco_id);
				
				if ($is_member) {
					$xavcMemberObj->updateXAVCMember();
				} else {
					$xavcMemberObj->insertXAVCMember();
				}
				
				$this->xmlApi->updateMeetingParticipant($sco_id, ilXAVCMembers::_lookupXAVCLogin($user_id), $session, $status);
			}
			
			foreach ($tutors as $user_id) {
				if ($user_id == $this->getOwner()) {
					continue;
				}
				
				$xavcRoles->addAdministratorRole($user_id);
				
				$is_member = ilXAVCMembers::_isMember($user_id, $ref_id);
				// local member table
				$xavcMemberObj = new ilXAVCMembers($ref_id, $user_id);
				
				$status = $role_map[$oParticipants->getType() . '_tutor'];
				
				$xavcMemberObj->setStatus($status);
				$xavcMemberObj->setScoId($sco_id);
				
				if ($is_member) {
					$xavcMemberObj->updateXAVCMember();
				} else {
					$xavcMemberObj->insertXAVCMember();
				}
				
				$this->xmlApi->updateMeetingParticipant($sco_id, ilXAVCMembers::_lookupXAVCLogin($user_id), $session, $status);
			}
			
			foreach ($members as $user_id) {
				if ($user_id == $this->getOwner()) {
					continue;
				}
				
				$xavcRoles->addMemberRole($user_id);
				$is_member = ilXAVCMembers::_isMember($user_id, $ref_id);
				// local member table
				$xavcMemberObj = new ilXAVCMembers($ref_id, $user_id);
				
				$status = $role_map[$oParticipants->getType() . '_member'];
				
				$xavcMemberObj->setStatus($status);
				$xavcMemberObj->setScoId($sco_id);
				
				if ($is_member) {
					$xavcMemberObj->updateXAVCMember();
				} else {
					$xavcMemberObj->insertXAVCMember();
				}
				
				$this->xmlApi->updateMeetingParticipant($sco_id, ilXAVCMembers::_lookupXAVCLogin($user_id), $session, $status);
			}
			
			$owner_id = ilObject::_lookupOwner($oParticipants->getObjId());
			
			$xavcRoles->addAdministratorRole($owner_id);
			
			$is_member = ilXAVCMembers::_isMember($owner_id, $ref_id);
			// local member table
			$xavcMemberObj = new ilXAVCMembers($ref_id, $owner_id);
			
			$status = $role_map[$oParticipants->getType() . '_owner'];
			$xavcMemberObj->setStatus($status);
			
			$xavcMemberObj->setScoId($sco_id);
			
			if ($is_member) {
				$xavcMemberObj->updateXAVCMember();
			} else {
				$xavcMemberObj->insertXAVCMember();
			}
			
			$this->xmlApi->updateMeetingParticipant($sco_id, ilXAVCMembers::_lookupXAVCLogin($owner_id), $session, $status);
		}
	}

	public function deleteCrsGrpMembers($sco_id, $delete_user_ids)
	{
		$this->pluginObj->includeClass('class.ilAdobeConnectRoles.php');
		$this->pluginObj->includeClass('class.ilXAVCMembers.php');
		
		$xavcRoles = new ilAdobeConnectRoles($this->getRefId());
		
		if (is_array($delete_user_ids) && count($delete_user_ids) > 0) {
			foreach ($delete_user_ids as $usr_id) {
				$xavcRoles->detachMemberRole($usr_id);
				
				ilXAVCMembers::deleteXAVCMember($usr_id, $this->getRefId());
				$xavc_login = ilXAVCMembers::_lookupXAVCLogin($usr_id);
				
				$session = $this->getSession(false, true);
				
				if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
					$this->xmlApi->deleteMeetingParticipant($sco_id, $xavc_login, $session);
				}
				
				// remove from pd
				ilObjUser::_dropDesktopItem($usr_id, $this->getRefId(), 'xavc');
			}
		}
	}

	/**
	 * Read data from db and from Adobe Connect server
	 */
	public function doRead()
	{
		if ($this->ctrl->isAsynch()) {
			return;
		}
		
		$set = $this->db->query("SELECT * FROM rep_robj_xavc_data " . " WHERE id = " . $this->db->quote($this->getId(), "integer"));
		
		while ($rec = $this->db->fetchAssoc($set)) {
			$this->sco_id = $rec["sco_id"];
			$this->instructions = $rec['instructions'];
			$this->contact_info = $rec['contact_info'];
			$this->permanent_room = $rec['permanent_room'];
			$this->read_contents = $rec['perm_read_contents'];
			$this->read_records = $rec['perm_read_records'];
			$this->folder_id = $rec['folder_id'];
			$this->url = $rec['url_path'];
			$this->setMeetingLang($rec['meeting_lang']);
			$this->setMaxPax($rec['max_pax']);
		}
		
		if ($this->sco_id == NULL) {
			// $this->ilias->raiseError($this->lng->txt("err_no_valid_sco_id_given"),$this->ilias->error_obj->MESSAGE);
		}
		
		$this->pluginObj->includeClass('class.ilAdobeConnectServer.php');
		$settings = ilAdobeConnectServer::_getInstance();
		
		$session = $this->getSession(true);
		
		if (($settings->getAuthMode() == ilAdobeConnectServer::AUTH_MODE_SWITCHAAI || $session != NULL) && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			// only read url via api, if url in database is empty
			if (! $this->url) {
				// the parameter meeting is used for the switchaai-case
				$this->url = substr($this->xmlApi->getURL($this->sco_id, $this->folder_id, $session, 'meeting'), 0, - 1);
			}
			
			$date_begin = $this->xmlApi->getStartDate($this->sco_id, $this->folder_id, $session);
			$this->start_date = new ilDateTime(strtotime($date_begin), IL_CAL_UNIX);
			$date_end_string = $this->xmlApi->getEndDate($this->sco_id, $this->folder_id, $session);
			$end_date = new ilDateTime(strtotime($date_end_string), IL_CAL_UNIX);
			$this->end_date = $end_date;
			$unix_duration = $end_date->getUnixTime() - $this->start_date->getUnixTime();
			
			$hours = floor($unix_duration / 3600);
			$minutes = floor(($unix_duration - $hours * 3600) / 60);
			$this->duration = array(
				"hours" => $hours,
				"minutes" => $minutes
			);
			
			$this->setCurrentPax($this->xmlApi->getCurrentPax($this->sco_id, $session));
			
			if (! $this->getMeetingLang()) {
				$this->meeting_lang = $this->xmlApi->getMeetingLang($this->sco_id, $session);
			}
			
			$this->pluginObj->includeClass('class.ilAdobeConnectContents.php');
			$this->contents = new ilAdobeConnectContents();
			
			$this->access_level = $this->xmlApi->getPermissionId($this->sco_id, $session);
		}
		$this->initParticipantsObject();
	}

	/**
	 * Update data
	 */
	public function doUpdate()
	{
		$session = $this->getSession();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$end_date = new ilDateTime($this->start_date->getUnixTime() + $this->duration["hours"] * 3600 + $this->duration["minutes"] * 60, IL_CAL_UNIX);
			$this->xmlApi->updateMeeting($this->sco_id, $this->getTitle(), $this->getDescription(), date('Y-m-d', $this->start_date->getUnixTime()), date('H:i', $this->start_date->getUnixTime()), date('Y-m-d', $end_date->getUnixTime()), date('H:i', $end_date->getUnixTime()), $this->getMeetingLang(), $session);
			
			$this->xmlApi->updatePermission($this->sco_id, $session, $this->permission);
		}
		
		$this->db->update('rep_robj_xavc_data', array(
			'start_date' => array(
				'integer',
				$this->getStartdate()
					->getUnixTime()
			),
			'end_date' => array(
				'integer',
				$this->getEnddate()
					->getUnixTime()
			),
			'instructions' => array(
				'text',
				$this->getInstructions()
			),
			'contact_info' => array(
				'text',
				$this->getContactInfo()
			),
			'permanent_room' => array(
				'integer',
				$this->getPermanentRoom()
			),
			'perm_read_contents' => array(
				'integer',
				$this->getReadContents()
			),
			'perm_read_records' => array(
				'integer',
				$this->getReadRecords()
			),
			'meeting_lang' => array(
				'text',
				$this->getMeetingLang()
			),
			'max_pax' => array(
				'integer',
				$this->getMaxPax()
			)
		), array(
			'sco_id' => array(
				'integer',
				$this->getScoId()
			)
		));
	}

	/**
	 * Delete data from db and from Adobe Connect server
	 */
	public function doDelete()
	{
		$session = $this->getSession(true);
		$this->xmlApi->login($this->adminLogin, $this->adminPass, $session);
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$this->xmlApi->deleteMeeting($this->sco_id, $session);
		}
		
		$this->db->manipulateF('DELETE FROM rep_robj_xavc_data WHERE id = %s', array(
			'integer'
		), array(
			$this->getId()
		));
		
		$this->db->manipulateF('DELETE FROM rep_robj_xavc_members WHERE sco_id = %s', array(
			'integer'
		), array(
			$this->sco_id
		));
	}

	/*
	 * Set/Get Methods for our virtual classroom properties
	 */
	
	/**
	 * Sets meeting start date
	 *
	 * @param ilDateTime $a_val
	 */
	public function setStartDate($a_val)
	{
		$this->start_date = $a_val;
	}

	/**
	 * Returns meeting start date
	 *
	 * @return ilDateTime
	 */
	public function getStartDate()
	{
		return $this->start_date;
	}

	/**
	 * Sets meeting contents
	 *
	 * @param ilAdobeConnectContents $a_val
	 */
	public function setContents($a_val)
	{
		$this->contents = $a_val;
	}

	/**
	 * Returns meeting contents
	 *
	 * @return ilAdobeConnectContents
	 */
	public function getContents()
	{
		return $this->contents;
	}

	/**
	 * Sets meeting duration
	 *
	 * @param array $a_val
	 */
	public function setDuration($a_val)
	{
		$this->duration = $a_val;
	}

	/**
	 * Returns meeting duration
	 *
	 * @return array
	 */
	public function getDuration()
	{
		return $this->duration;
	}

	/**
	 * Sets meeting id
	 *
	 * @param String $a_val
	 */
	public function setScoId($a_val)
	{
		$this->sco_id = $a_val;
	}

	/**
	 * Returns meeting id
	 *
	 * @return String
	 */
	public function getScoId()
	{
		return $this->sco_id;
	}

	/**
	 * Sets meeting URL
	 *
	 * @param String $a_val
	 */
	public function setUrl($a_val)
	{
		$this->url = $a_val;
	}

	/**
	 * Returns meeting URL
	 *
	 * @return String
	 */
	public function getUrl()
	{
		return $this->url;
	}

	public function setPermission($a_permission)
	{
		$this->permission = $a_permission;
	}

	public function getPermission()
	{
		return $this->permission;
	}

	/**
	 *
	 * @param String $instructions
	 */
	public function setInstructions($instructions)
	{
		$this->instructions = $instructions;
	}

	/**
	 *
	 * @return String
	 */
	public function getInstructions()
	{
		return $this->instructions;
	}

	/**
	 *
	 * @param null $contact_info
	 */
	public function setContactInfo($contact_info)
	{
		$this->contact_info = $contact_info;
	}

	/**
	 *
	 * @return null
	 */
	public function getContactInfo()
	{
		return $this->contact_info;
	}

	/**
	 *
	 * @param int $permanent_room
	 */
	public function setPermanentRoom($permanent_room)
	{
		$this->permanent_room = $permanent_room;
	}

	/**
	 *
	 * @return int
	 */
	public function getPermanentRoom()
	{
		return $this->permanent_room;
	}

	/**
	 *
	 * @param int $read_contents
	 */
	public function setReadContents($read_contents)
	{
		$this->read_contents = $read_contents;
	}

	/**
	 *
	 * @return int
	 */
	public function getReadContents()
	{
		return $this->read_contents;
	}

	/**
	 *
	 * @param int $read_records
	 */
	public function setReadRecords($read_records)
	{
		$this->read_records = $read_records;
	}

	/**
	 *
	 * @return int
	 */
	public function getReadRecords()
	{
		return $this->read_records;
	}

	/**
	 *
	 * @param int $folder_id
	 */
	public function setFolderId($folder_id)
	{
		$this->folder_id = $folder_id;
	}

	/**
	 *
	 * @return int
	 */
	public function getFolderId()
	{
		return $this->folder_id;
	}

	/**
	 * Returns meeting end date
	 *
	 * @return ilDateTime
	 */
	public function getEndDate()
	{
		$end_date = new ilDateTime($this->start_date->getUnixTime(), IL_CAL_UNIX);
		$end_date->increment(ilDateTime::HOUR, $this->duration["hours"]);
		$end_date->increment(ilDateTime::MINUTE, $this->duration["minutes"]);
		return $end_date;
	}

	/**
	 *
	 * @param
	 *        	String Two letter language code
	 */
	public function setMeetingLang($meeting_lang)
	{
		$this->meeting_lang = $meeting_lang;
	}

	/**
	 *
	 * @return String Two letter language code
	 */
	public function getMeetingLang()
	{
		return $this->meeting_lang;
	}

	/**
	 *
	 * @param
	 *        	integer max number of participants
	 */
	public function setMaxPax($max_pax)
	{
		if ($max_pax == 0 || ! isset($max_pax) || $max_pax == '') {
			$this->pluginObj->includeClass('class.ilAdobeConnectServer.php');
			$settings = ilAdobeConnectServer::_getInstance();
			$settings->getSetting('max_pax') ? $max_pax = $settings->getSetting('max_pax') : $max_pax = 200;
		}
		
		$this->max_pax = $max_pax;
	}

	/**
	 *
	 * @return integer max number of participants
	 */
	public function getMaxPax()
	{
		return $this->max_pax;
	}

	/**
	 *
	 * @param
	 *        	integer number of current participants in meeting
	 */
	public function setCurrentPax($current_pax)
	{
		$this->current_pax = $current_pax;
	}

	/**
	 *
	 * @return integer number of current participants in meeting
	 */
	public function getCurrentPax()
	{
		return $this->current_pax;
	}

	/*
	 * Contents functions
	 */
	
	/**
	 * Reads contents from Adobe Connect server
	 *
	 * @param string $by_type
	 *        	null|content|record
	 * @return bool
	 */
	public function readContents($by_type = NULL)
	{
		$session = $this->getSession();
		
		$ids = array();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$ids = ($this->xmlApi->getContentIds($this->sco_id, $session) ? $this->xmlApi->getContentIds($this->sco_id, $session) : array());
			
			foreach ($ids as $id) {
				$date_created = $this->xmlApi->getDateCreated($id, $this->sco_id, $session);
				
				$date_end = $this->xmlApi->getDateEnd($id, $this->sco_id, $session);
				if ($date_end == '') {
					$type = 'content';
				} else {
					$type = 'record';
				}
				
				if ($by_type == NULL || $by_type == $type) {
					$attributes = array(
						"sco-id" => $id,
						"name" => $this->xmlApi->getName($id, $this->sco_id, $session),
						"url" => $this->xmlApi->getURL($id, $this->sco_id, $session),
						"date-created" => new ilDateTime(substr($date_created, 0, 10) . " " . substr($date_created, 11, 8), IL_CAL_DATETIME),
						"date-end" => $date_end,
						"description" => $this->xmlApi->getDescription($id, $this->sco_id, $session),
						"type" => $type
					);
					$this->contents->addContent($attributes);
				}
			}
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Reads records from Adobe Connect server
	 */
	public function readRecords()
	{
		$session = $this->getSession(false, true);
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$ids = $this->xmlApi->getRecordIds($this->sco_id, $session);
			foreach ($ids as $id) {
				$date_created = $this->xmlApi->getDateCreated($id, $this->sco_id, $session);
				$attributes_records = array(
					"sco-id" => $id,
					"name" => $this->xmlApi->getName($id, $this->getScoId(), $session),
					"url" => $this->xmlApi->getURL($id, $this->sco_id, $session),
					"date-created" => new ilDateTime(substr($date_created, 0, 10) . " " . substr($date_created, 11, 8), IL_CAL_DATETIME),
					"duration" => $this->xmlApi->getDuration($id, $this->sco_id, $session),
					"description" => $this->xmlApi->getDescription($id, $this->sco_id, $session),
					"type" => "record"
				);
				$this->contents->addContent($attributes_records);
			}
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Returns the contents containing the search criteria
	 *
	 * @param array $search_criteria
	 * @return array
	 */
	public function searchContent($search_criteria)
	{
		return $this->contents->search($search_criteria);
	}

	/**
	 * Returns the content associated with the identifier
	 *
	 * @param String $sco_id
	 * @return ilAdobeConnectContent
	 */
	public function getContent($sco_id)
	{
		$contents = $this->searchContent(array(
			"sco-id" => $sco_id
		));
		
		return $contents[0];
	}

	/**
	 * Changes the visibility of content
	 *
	 * @param String $sco_id
	 * @param String $permission
	 */
	public function changeContentVisibility($sco_id, $permission)
	{
		$session = self::getSession();
		$this->xmlApi->changeContentVisibility($sco_id, $session, $permission);
	}

	/**
	 * Adds a content to the Adobe Connect server
	 *
	 * @param String $title
	 * @param String $description
	 * @return String
	 * @throws ilAdobeConnectDuplicateContentException
	 */
	public function addContent($title = "untitled", $description = "")
	{
		$session = $this->getSession();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			return $this->xmlApi->addContent($this->sco_id, $title, $description, $session);
		}
	}

	/**
	 * Updates a content on the Adobe Connect server
	 *
	 * @param String $sco_id
	 * @param String $title
	 * @param String $description
	 * @throws ilAdobeConnectDuplicateContentException
	 */
	public function updateContent($sco_id, $title, $description)
	{
		$session = $this->getSession();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$this->xmlApi->updateContent($sco_id, $title, $description, $session);
		}
	}

	/**
	 * Removes a content from the Adobe Connect server
	 *
	 * @param String $sco_id
	 */
	public function deleteContent($sco_id)
	{
		$session = $this->getSession(false, true);
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$this->xmlApi->deleteContent($sco_id, $session);
		}
	}

	/**
	 * Uploads a content to the Adobe Connect server
	 *
	 * @param String $sco_id
	 * @return String
	 */
	public function uploadContent($sco_id)
	{
		$session = $this->getSession(false, true);
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session))
			return $this->xmlApi->uploadContent($sco_id, $session);
	}

	/*
	 * Participants functions
	 */
	
	/**
	 * Returns meeting hosts
	 *
	 * @return array
	 */
	public function getParticipants()
	{
		$session = $this->getSession();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session) && $this->xmlApi->isParticipant($this->adminLogin, $this->sco_id, $session)) {
			return $this->xmlApi->getMeetingsParticipants($this->sco_id, $session);
		} else {
			return NULL;
		}
	}

	/**
	 * Add a new host to the meeting
	 *
	 * @param String $login
	 * @return boolean Returns true if everything is ok
	 */
	public function addParticipant($login)
	{
		$session = $this->getSession();
		
		// check if adobe connect account exists
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$participant = $this->xmlApi->addMeetingParticipant($this->sco_id, $login, $session);
			return $participant;
		}
	}

	/**
	 * Update switch participant at login if needed
	 *
	 * @param String $login
	 * @return String access-rights of user
	 *        
	 */
	public function updateSwitchParticipant($adobe_login_name)
	{
		$this->pluginObj->includeClass('class.ilXAVCMembers.php');
		
		$user_id = $this->user->getId();
		$ref_id = $this->getRefId();
		$status = ilXAVCMembers::_lookupStatus($user_id, $ref_id);
		if (ilXAVCMembers::_lookupXAVCLogin($user_id) != $adobe_login_name) {
			$member = new ilXAVCMembers($ref_id, $user_id);
			$member->updateXAVCMember();
		}
		
		$session = $this->getSession(false, true);
		
		if ($status != $this->xmlApi->getMeetingPermission($adobe_login_name, $this->sco_id, $session)) {
			if (empty($status))
				$status = 'remove';
			$this->xmlApi->updateMeetingParticipantByTechnicalUser($this->getScoId(), $adobe_login_name, $session, $status);
		}
		
		return $this->xmlApi->getMeetingPermission($adobe_login_name, $this->sco_id, $session);
	}

	public function updateParticipant($login, $permission)
	{
		$session = $this->getSession();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			return $this->xmlApi->updateMeetingParticipant($this->sco_id, $login, $session, $permission);
		}
	}

	/**
	 * Deletes a host from the meeting
	 *
	 * @param String $login
	 * @return boolean Returns true if everything is ok
	 */
	public function deleteParticipant($login)
	{
		$session = $this->getSession(false, true);
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session))
			return $this->xmlApi->deleteMeetingParticipant($this->sco_id, $login, $session);
	}

	/**
	 * Check whether a user is host in this virtual classroom.
	 *
	 * @param String $login
	 * @return boolean
	 */
	public function isParticipant($login)
	{
		$this->pluginObj->includeClass('class.ilAdobeConnectServer.php');
		$settings = ilAdobeConnectServer::_getInstance();
		
		if ($settings->getAuthMode() == ilAdobeConnectServer::AUTH_MODE_SWITCHAAI) {
			$this->pluginObj->includeClass('class.ilXAVCMembers.php');
			return ilXAVCMembers::_isMember($this->user->getId(), $this->getRefId());
		} else {
			$session = $this->getSession(false, true);
			
			if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session))
				return $this->xmlApi->isParticipant($login, $this->sco_id, $session);
		}
	}

	/**
	 *
	 * @param
	 *        	string Adobe Login Name
	 *        	
	 * @return integer Principal Id
	 */
	public function getPrincipalId($login)
	{
		$session = $this->getSession();
		
		return $this->xmlApi->getPrincipalId($login, $session);
	}

	public function getPermissionId()
	{
		$session = $this->getSession();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$permission = $this->xmlApi->getPermissionId($this->sco_id, $session);
		}
		return $permission;
	}

	public function getContentPermissionId($sco_id)
	{
		$session = self::getSession();
		
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$permission = $this->xmlApi->getPermissionId($sco_id, $session);
		}
		return $permission;
	}

	// LOCAL ROLES FOR ILIAS
	public function initDefaultRoles()
	{
		include_once 'class.ilObjAdobeConnectAccess.php';
		include_once './Services/AccessControl/classes/class.ilObjRole.php';
		
		ilObjAdobeConnectAccess::getLocalAdminRoleTemplateId();
		ilObjAdobeConnectAccess::getLocalMemberRoleTemplateId();
		
		$admin_role = ilObjRole::createDefaultRole('il_xavc_admin_' . $this->getRefId(), 'Admin of Adobe Connect object with obj_no.' . $this->getId(), 'il_xavc_admin', $this->getRefId());
		
		$member_role = ilObjRole::createDefaultRole('il_xavc_member_' . $this->getRefId(), 'Member of Adobe Connect object with obj_no.' . $this->getId(), 'il_xavc_member', $this->getRefId());
		
		$ops = $this->rbacreview->getOperationsOfRole($member_role->getId(), 'xavc', $this->getRefId());
		
		// Set view permission for users
		$this->rbacadmin->grantPermission(self::RBAC_DEFAULT_ROLE_ID, $ops, $this->getRefId());
		// Set view permission for guests
		$this->rbacadmin->grantPermission(self::RBAC_GUEST_ROLE_ID, array(
			2
		), $this->getRefId());
		
		$roles = array(
			$admin_role->getId(),
			$member_role->getId()
		);
		
		return $roles ? $roles : array();
	}

	/**
	 * Returns all meetings that takes place during the current meeting object
	 *
	 * @return boolean
	 */
	public function checkConcurrentMeetingDates()
	{
		require_once dirname(__FILE__) . '/class.ilAdobeConnectQuota.php';
		$quota = new ilAdobeConnectQuota();
		
		return $quota->checkConcurrentMeetingDates($this->getEndDate(), $this->getStartDate(), $this->getId() ? $this->getId() : null);
	}

	public static function getObjectData($obj_id)
	{
		global $DIC;
		
		$query = 'SELECT * FROM rep_robj_xavc_data WHERE id = %s';
		$types = array(
			'integer'
		);
		$values = array(
			$obj_id
		);
		
		$res = $DIC->database()->queryF($query, $types, $values);
		
		return $DIC->database()->fetchObject($res);
	}

	/**
	 * Returns a List of Meetings that takes place in the time between $startDate and $endDate.
	 * A Meeting is in range if $startDate > start_date < $endDate or $startDate > end_date < $endDate.
	 *
	 * @param integer $startDate
	 *        	unixtimestamp
	 * @param integer $endDate
	 *        	unixtimestamp
	 */
	public static function getMeetingsInRange($startDate, $endDate)
	{
		global $DIC;
		$query = 'SELECT * FROM rep_robj_xavc_data WHERE (start_date > %s AND start_date < %s) OR (end_date > %s AND end_date < %s) ORDER BY start_date';
		$types = array(
			'integer',
			'integer',
			'integer',
			'integer'
		);
		$values = array(
			$startDate,
			$endDate,
			$startDate,
			$endDate
		);
		
		$res = $DIC->database()->queryF($query, $types, $values);
		
		$objects = array();
		
		while ($row = $DIC->database()->fetchObject($res)) {
			if (ilObject::_hasUntrashedReference($row->id)) {
				$objects[] = $row;
			}
		}
		
		return $objects;
	}

	public static function getLocalScos()
	{
		global $DIC;
		$res = $DIC->database()->query('SELECT sco_id FROM rep_robj_xavc_data');
		while ($row = $DIC->database()->fetchAssoc($res)) {
			$local_scos[] = $row['sco_id'];
		}
		return $local_scos;
	}

	public static function _lookupScoId($a_obj_id)
	{
		global $DIC;
		$res = $DIC->database()->queryF('SELECT sco_id FROM rep_robj_xavc_data WHERE id = %s', array(
			'integer'
		), array(
			$a_obj_id
		));
		
		$row = $DIC->database()->fetchAssoc($res);
		
		return $row['sco_id'];
	}

	public static function getScosByFolderId($folder_id)
	{
		$instance = ilAdobeConnectServer::_getInstance();
		$adminLogin = $instance->getLogin();
		$adminPass = $instance->getPasswd();
		
		$xmlApi = ilXMLApiFactory::getApiByAuthMode();
		
		$session = $xmlApi->getBreezeSession();
		
		if ($session != NULL && $xmlApi->login($adminLogin, $adminPass, $session)) {
			$scos = $xmlApi->getScosByFolderId($folder_id, $session);
		}
		return $scos;
	}

	public function getFolderIdByLogin($externalLogin)
	{
		$session = $this->getSession(false, true);
		if (ilAdobeConnectServer::getSetting('use_user_folders') == 1) {
			$folder_id = $this->xmlApi->lookupUserFolderId($externalLogin, $session);
			
			if (! $folder_id) {
				$folder_id = $this->xmlApi->createUserFolder($externalLogin, $session);
			}
		} else {
			$folder_id = $this->xmlApi->getShortcuts("my-meetings", $session);
		}
		return $folder_id;
	}

	/**
	 *
	 * @param
	 *        	$sco_id
	 * @return array
	 */
	public function getContentIconAttribute($sco_id)
	{
		$session = $this->getSession();
		
		$icons = array();
		if ($session != NULL && $this->xmlApi->login($this->adminLogin, $this->adminPass, $session)) {
			$icons[] = $this->xmlApi->getContentIconAttribute($sco_id, $this->sco_id, $session);
		}
		return $icons;
	}

	/**
	 *
	 * @param string $url
	 * @param string $filePath
	 * @param string $title
	 */
	public function uploadFile($url, $filePath, $title = '')
	{
		global $DIC;
		
		if (function_exists('curl_file_create')) {
			$curlFile = curl_file_create($filePath);
		} else {
			$curlFile = '@' . realpath($filePath);
		}
		
		$postData = array(
			'file' => $curlFile
		);
		if (strlen($title) > 0) {
			$postData['name'] = $title;
		}
		
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_VERBOSE, true);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
		$postResult = curl_exec($curl);
		curl_close($curl);
		
		$DIC->logger()->root()->write("AdobeConnect: addContent result ...");
		$DIC->logger()->root()->write($postResult);
	}

	/**
	 *
	 * @return bool
	 */
	public function isUseMeetingTemplate()
	{
		return $this->use_meeting_template;
	}

	/**
	 *
	 * @param bool $use_meeting_template
	 */
	public function setUseMeetingTemplate($use_meeting_template)
	{
		$this->use_meeting_template = $use_meeting_template;
	}

	/**
	 * Returns the Adobe User name of the current user on a switch server
	 *
	 * @return mixed string with Adobe user name or the xml response object, if something went wrong
	 */
	public function getCurrentUserSwitchUserName()
	{
		return $this->xmlApi->getCurrentUserSwitchUserName();
	}

	/**
	 * Returns a session variable, needed to avoid login at open on SwitchAAI-Installations
	 *
	 * @return string with session id, false if SwitchAAI and no session and NULL if non
	 *         SwitchAAI and error creating session.
	 */
	private function getSession($forceAdmin = false, $forceUser = false)
	{
		$this->pluginObj->includeClass('class.ilAdobeConnectServer.php');
		$settings = ilAdobeConnectServer::_getInstance();
		
		$session = 'false';
		
		if ($forceUser == true || $settings->getAuthMode() != ilAdobeConnectServer::AUTH_MODE_SWITCHAAI) {
			$session = $this->xmlApi->getBreezeSession();
		} else if (! forceAdmin) {
			$session = $this->xmlApi->checkBreezeSession();
		}
		
		if ($session == 'false' && $settings->getAuthMode() == ilAdobeConnectServer::AUTH_MODE_SWITCHAAI) {
			$session = $this->xmlApi->login($this->adminLogin, $this->adminPass);
		}
		
		return $session;
	}
}
