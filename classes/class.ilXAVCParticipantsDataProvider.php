<?php

/* Copyright (c) 1998-2013 ILIAS open source, Extended GPL, see docs/LICENSE */

require_once dirname(__FILE__) . '/class.ilAdobeConnectTableDatabaseDataProvider.php';

/**
 * @version $Id$
 */
class ilXAVCParticipantsDataProvider extends ilAdobeConnectTableDatabaseDataProvider
{
	/**
	 * @return string
	 */
	protected function getSelectPart(array $filter)
	{

		$fields = array(
			'rep_robj_xavc_members.user_id',
			'rep_robj_xavc_members.xavc_status',
			'usr_data.lastname',
			'usr_data.firstname',
			'usr_data.login',
			'usr_data.email'
	
		);

		return implode(', ', $fields);
	}

	/**
	 * @return string
	 */
	protected function getFromPart(array $filter)
	{
		$joins = array(
			'INNER JOIN usr_data ON usr_data.usr_id = user_id',
		);

		return 'rep_robj_xavc_members ' . implode(' ', $joins);
	}

	/**
	 * @param array $filter
	 * @return string
	 */
	protected function getWherePart(array $filter)
	{
		$where = array();

		$where[] = " rep_robj_xavc_members.ref_id = ". $this->db->quote($this->parent_obj->ref_id,'integer');

		return implode(' AND ', $where);
	}

	/**
	 * @return string
	 */
	protected function getGroupByPart()
	{
		return '';
	}

	/**
	 * @param array $filter
	 * @return mixed
	 */
	protected function getHavingPart(array $filter)
	{
		return '';
	}

	/**
	 * @param array $params
	 * @return string
	 * @throws InvalidArgumentException
	 */
	protected function getOrderByPart(array $params)
	{
		if(isset($params['order_field']))
		{
			if(!is_string($params['order_field']))
			{
				throw new InvalidArgumentException('Please provide a valid order field.');
			}

			$fields = array(
				'user_id',
				'lastname',
				'firstname',
				'login',
				'email',
				'xavc_status'
			);

			if(!in_array($params['order_field'], $fields))
			{
				$params['order_field'] = 'user_id';
			}

			if(!isset($params['order_direction']))
			{
				$params['order_direction'] = 'ASC';
			}
			else if(!in_array(strtolower($params['order_direction']), array('asc', 'desc')))
			{
				throw new InvalidArgumentException('Please provide a valid order direction.');
			}

                        return $params['order_field'] . ' ' . $params['order_direction'];
		}

		return '';
	}


	protected function getAdditionalItems($data)
	{
		$xavc_participants = $this->parent_obj->object->getParticipants();
		$selected_user_ids = array();

		foreach($data['items'] as $db_item)
		{
			$selected_user_ids[] = (int)$db_item['user_id'];
		}

		if ($xavc_participants != NULL)
		{
			foreach($xavc_participants as $participant)
			{
				$user_id = ilXAVCMembers::_lookupUserId($participant['login']);
                //if the user_id is in the xavc members table in ilias (->$selected_user_ids), all information is already in $data['items'], so we just continue.
                //if the user_id belongs to the technical user, we just continue, because we don't want him to be shown
                if(in_array((int)$user_id, $selected_user_ids) || $participant['login'] == ilAdobeConnectServer::getSetting('login'))
                {
                    continue;
                }

                //when user_id is bigger than 0, he exists. So we get it's information by using ilObjUser
				if($user_id > 0)
				{
					$tmp_user = ilObjectFactory::getInstanceByObjId($user_id, false);
					if(!$tmp_user)
					{
						// Maybe delete entries xavc_members xavc_users tables 
						continue;
					}

					$firstname =  $tmp_user->getFirstname();
					$lastname =  $tmp_user->getLastname();
					if($tmp_user->hasPublicProfile() && $tmp_user->getPref('public_email') == 'y')
					{
						$user_mail = $tmp_user->getEmail();
					}
					else
					{
						$user_mail = '';
					}
				}
				else
				{
					$firstname = $participant['name'];
					$user_mail = '';
				}
				
				$ac_user['user_id'] = $user_id;
				$ac_user['firstname'] = $firstname;
				$ac_user['lastname'] = $lastname;
				$ac_user['login'] = $participant['login'];
				$ac_user['email'] = $user_mail;
				$ac_user['xavc_status'] = $participant['status'];
				
				
				$data['items'][] = $ac_user;
			}
		}
		
		return $data;
	}
}
