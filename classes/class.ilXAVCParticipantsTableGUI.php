<?php
require_once dirname(__FILE__) . '/class.ilAdobeConnectTableGUI.php';
require_once 'Services/UIComponent/AdvancedSelectionList/classes/class.ilAdvancedSelectionListGUI.php';

class ilXAVCParticipantsTableGUI extends ilAdobeConnectTableGUI
{

	/**
	 *
	 * @param
	 *        	$a_parent_obj
	 * @param string $a_parent_cmd
	 */
	public function __construct($a_parent_obj, $a_parent_cmd)
	{
		parent::__construct($a_parent_obj, $a_parent_cmd);
		
		$this->tpl->addJavascript("./Customizing/global/plugins/Services/Repository/RepositoryObject/AdobeConnect/templates/js/plugin.js");

		$this->setId('xavc_participants');

		$this->setDefaultOrderDirection('ASC');
		$this->setDefaultOrderField('');
		$this->setExternalSorting(false);
		$this->setExternalSegmentation(false);
		$this->pluginObj = ilPlugin::getPluginObject('Services', 'Repository', 'robj', 'AdobeConnect');

		$this->setEnableNumInfo(true);

		$this->setTitle($a_parent_obj->pluginObj->txt("participants"));
		$this->addColumns();
		$this->addMultiCommands();

		$this->setSelectAllCheckbox('usr_id[]');
		$this->setShowRowsSelector(true);

		$this->setFormAction($this->ctrl->getFormAction($a_parent_obj));
		$this->setRowTemplate($a_parent_obj->pluginObj->getDirectory() . '/templates/default/tpl.xavc_active_user_row.html');
	}

	private function addMultiCommands()
	{
		global $DIC;
		$ilUser = $DIC->user();
		$rbacsystem = $DIC->rbac()->system();

		$this->parent_obj->pluginObj->includeClass('class.ilXAVCPermissions.php');

		$isadmin = $rbacsystem->checkAccessOfUser($ilUser->getId(), 'write', $this->parent_obj->ref_id);
		$this->parent_obj->pluginObj->includeClass('class.ilAdobeConnectServer.php');
		$settings = ilAdobeConnectServer::_getInstance();

		if (ilXAVCPermissions::hasAccess($ilUser->getId(), $this->parent_obj->ref_id, AdobeConnectPermissions::PERM_CHANGE_ROLE) || $isadmin && $settings->getAuthMode() == ilAdobeConnectServer::AUTH_MODE_SWITCHAAI) {
			$this->addMultiCommand('updateParticipants', $this->lng->txt('update'));
			$this->addMultiCommand('makeHosts', $this->pluginObj->txt('make_hosts'));
			$this->addMultiCommand('makeModerators', $this->pluginObj->txt('make_moderators'));
			$this->addMultiCommand('makeParticipants', $this->pluginObj->txt('make_participants'));
			$this->addMultiCommand('makeBlocked', $this->pluginObj->txt('make_blocked'));
		}

		$this->parent_obj->pluginObj->includeClass('class.ilAdobeConnectServer.php');
		$settings = ilAdobeConnectServer::_getInstance();

		if ((ilXAVCPermissions::hasAccess($ilUser->getId(), $this->parent_obj->ref_id, AdobeConnectPermissions::PERM_ADD_PARTICIPANTS) || $isadmin && $settings->getAuthMode() == ilAdobeConnectServer::AUTH_MODE_SWITCHAAI) && ! $settings->getSetting('allow_crs_grp_trigger')) {
			$this->addMultiCommand('detachMember', $this->lng->txt('delete'));
		}
	}

	/**
	 *
	 * @param array $row
	 * @return array
	 */
	protected function prepareRow(array &$row)
	{
		if ((int) $row['user_id']) {
			$this->ctrl->setParameter($this->parent_obj, 'usr_id', '');
			if ($row['user_id'] == $this->parent_obj->object->getOwner()) {
				$row['checkbox'] = ilUtil::formCheckbox(false, 'usr_id[]', $row['user_id'], true);
			} else {
				$row['checkbox'] = ilUtil::formCheckbox(false, 'usr_id[]', $row['user_id'], (int) $row['user_id'] ? false : true);
			}
		} else {
			$row['checkbox'] = '';
		}

		$user_name = '';
		if (strlen($row['lastname']) > 0) {
			$user_name .= $row['lastname'] . ', ';
		}
		if (strlen($row['firstname']) > 0) {
			$user_name .= $row['firstname'];
		}
		$row['user_name'] = $user_name;

		if ($row['xavc_status']) {
			$xavc_options = array(
				"host" => $this->parent_obj->pluginObj->txt("presenter"),
				"mini-host" => $this->parent_obj->pluginObj->txt("moderator"),
				"view" => $this->parent_obj->pluginObj->txt("participant"),
				"denied" => $this->parent_obj->pluginObj->txt("denied")
			);

			if ($row['xavc_status']) {
				if ($row['user_id'] == $this->parent_obj->object->getOwner()) {
					$row['xavc_status'] = $this->lng->txt("owner");
				} else {
					$row['xavc_status'] = ilUtil::formSelect($row['xavc_status'], 'xavc_status[' . $row['user_id'] . ']', $xavc_options);
				}
			} else {
				$row['xavc_status'] = $this->parent_obj->pluginObj->txt('user_only_exists_at_ac_server');
			}
		}
	}

	/**
	 */
	public function initFilter()
	{}

	/**
	 */
	private function addColumns()
	{
		$this->addColumn('', '', '1px', true);
		$this->addColumn($this->lng->txt('name'), 'user_name');
		$this->optionalColumns = (array) $this->getSelectableColumns();
		$this->visibleOptionalColumns = (array) $this->getSelectedColumns();
		foreach ($this->visibleOptionalColumns as $column) {
			$this->addColumn($this->optionalColumns[$column]['txt'], $column);
		}
		$this->addColumn($this->parent_obj->pluginObj->txt('user_status'), 'xavc_status');
	}

	/**
	 *
	 * @return array
	 */
	public function getSelectableColumns()
	{
		$cols = array(
			'login' => array(
				'txt' => $this->lng->txt('login'),
				'default' => true
			),
			'email' => array(
				'txt' => $this->lng->txt('email'),
				'default' => false
			)
		);

		return $cols;
	}

	/**
	 * Define a final formatting for a cell value
	 *
	 * @param mixed $column
	 * @param array $row
	 * @return mixed
	 */
	protected function formatCellValue($column, array $row)
	{
		return $row[$column];
	}

	/**
	 *
	 * @param string $field
	 * @return bool
	 */
	public function numericOrdering($field)
	{
		$sortables = array();

		if (in_array($field, $sortables)) {
			return true;
		}

		return false;
	}

	/**
	 *
	 * @return array
	 */
	protected function getStaticData()
	{
		return array(
			'checkbox',
			'user_name',
			'login',
			'xavc_status'
		);
	}
}