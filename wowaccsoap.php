<?php
/**
 * @version    $Id: myauth.php 7180 2007-04-23 16:51:53Z jinx $
 * @package    Joomla.Tutorials
 * @subpackage Plugins
 * @license    GNU/GPL
 */
 
// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();
 
class plgUserWowaccsoap extends JPlugin
{
    function onUserBeforeSave($user, $isnew, $new)
    {		
		//needed to get unhashed password
		$pass = $_POST["password"];
		//if not empty then it comes from kunena
		if (empty($pass)){
			//empty so it comes directly from Joomla
			$post_array = JFactory::getApplication()->input->get('jform', array(), 'ARRAY');
			$pass = $post_array['password'];
		}
		//check if a new password was set
		if ($user['password'] != $new['password']) {
			$wowpass = $pass;
		}
		else {
			$wowpass = '';
		}
		$usergroupold = $user['groups'];
		$usergroup = $new['groups'];
		//check if groups was altered
		if (!(array_diff($usergroupold, $usergroup)) && !(array_diff($usergroup, $usergroupold))) {
			$newgroups = false;
		}
		else {
			//was altered (maybe not a impotant groupchange, but to lazy to write that code)
			$newgroups = false;
		}
		//save hashed password in new session variable
		$session = JFactory::getSession();
		$session->set('wowpass', $wowpass); 
		$session->set('newgroups', $newgroups);
    }
    function onUserAfterSave($user, $isnew, $success, $msg)
    {	
		//Check if user was successfully stored in the database
		if (!$success){
			JFactory::getApplication()->enqueueMessage('User was not stored/altered in the WoW-Database since it was not stored/altered in the Joomla Database');
		}
		else {
			//Load settings
			$option['id-mod'] 			= $this->params->get('id-mod');   
			$option['id-gm'] 			= $this->params->get('id-gm');      
			$option['id-admin'] 		= $this->params->get('id-admin'); 
			$option['id-ignore-group'] 	= $this->params->get('id-ignore-group'); 
			$option['id-ignore-user'] 	= $this->params->get('id-ignore-user');
			
			$soapUsername 				= $this->params->get('soap-user');
			$soapPassword 				= $this->params->get('soap-pass');
			$soapHost 					= $this->params->get('soap-host');
			$soapPort 					= $this->params->get('soap-port');
			$soapcommand 				= "";
			
			$client = new SoapClient(NULL, array(
				'location' => "http://$soapHost:$soapPort/",
				'uri'      => 'urn:MaNGOS',
				'style'    => SOAP_RPC,
				'login'    => $soapUsername,
				'password' => $soapPassword,
			));
			//Load new Values (saved in Session in onUserBeforeSave)
			$session = JFactory::getSession();
			$wowpass = $session->get('wowpass');
			$newgroups = $session->get('newgroups');
			$wowmail = $user['email'];
			$wowuser = $user['username'];
			//Get Databasesession
			//SQL-Settings
			$gmlvl = 0;
			$modgmlvl = false;
			
			//New account or existing one being altered?
			if ($isnew) {
				$soapcommand = "account create $wowuser $wowpass";
			}		
			else {
				//Any changes to email, group or password?
				if ((empty($wowpass)) && (!$newgroups)){
					//no changes made
					return;
				}
				//Is a new password set?
				if (!empty($wowpass)){
					//password is not empty, so password has changed
					$soapcommand = "account set password $wowuser $wowpass $wowpass";
				}
			}
			//if $soapcommand not empty, execute
			if (!empty($soapcommand)){
				$result = $client->executeCommand(new SoapParam($soapcommand, 'command'));
				JFactory::getApplication()->enqueueMessage($result);
				$soapcommand = '';
			}
			//Also change WoW-Rank?
			if (!empty($option['id-mod']) || !empty($option['id-gm']) || !empty($option['id-admin'])) {
				//one or more fields are set --> Check if user should be skipped
				if (!(in_array($user['id'], explode(',', $option['id-ignore-user'])))) {
					//Not in list of ignored users --> Check if group should be skipped
					$usergroup = $user['groups']; //JAccess::getGroupsByUser($user['id']);
					if (count( array_intersect($usergroup, explode(',', $option['id-ignore-group']))) == 0) {
						//Not in list of ignored groups  --> First check admin then GM then Mod
						if (count( array_intersect($usergroup, explode(',', $option['id-admin']))) > 0) {
							$gmlvl = 3; //User is Admin
						}
						elseif (count( array_intersect($usergroup, explode(',', $option['id-gm']))) > 0) {
							$gmlvl = 2; //User is GM
						}
						elseif (count( array_intersect($usergroup, explode(',', $option['id-mod']))) > 0) {
							$gmlvl = 1; //User is Mod
						}
						$modgmlvl = true;
					}
				}
			}
			if ($isnew && ($gmlevel > 0)) {
				$soapcommand = "account set gmlevel $wowuser $gmlevel";
				JFactory::getApplication()->enqueueMessage("$wowuser $gmlevel");
			}
			else {
				if ($modgmlvl) {
					$soapcommand = "account set gmlevel $wowuser $gmlevel";
					JFactory::getApplication()->enqueueMessage("$wowuser $gmlevel");
				}
			}
			//if $soapcommand not empty, execute
			if (!empty($soapcommand)){
				$result = $client->executeCommand(new SoapParam($soapcommand, 'command'));
				JFactory::getApplication()->enqueueMessage($result);
				$soapcommand = '';
			}
			
			//Block, Delete user?
			if (($this->params->get('joomlablock') == 'on') && $user['block'] ) {
				$this->onUserAfterDelete($user, true, $msg);
			}
			elseif (($this->params->get('wowenable') == 'on') && !$user['block']) {
				$soapcommand = "account lock off";
				$result = $client->executeCommand(new SoapParam($soapcommand, 'command'));
				JFactory::getApplication()->enqueueMessage($result);
			}
		}
    }

	function onUserAfterDelete($user, $success, $msg ) {
		//Load settings       
		$option['lock'] 			= $this->params->get('wowlock'); 
		$soapUsername 				= $this->params->get('soap-user');
		$soapPassword 				= $this->params->get('soap-pass');
		$soapHost 					= $this->params->get('soap-host');
		$soapPort 					= $this->params->get('soap-port');
		$soapcommand 				= "";		
		
		$client = new SoapClient(NULL, array(
				'location' => "http://$soapHost:$soapPort/",
				'uri'      => 'urn:MaNGOS',
				'style'    => SOAP_RPC,
				'login'    => $soapUsername,
				'password' => $soapPassword,
			));
			
		if ($success) {
			//Lock or Delete WoW-Account?
			if ($option['lock'] == "lock") {
				 $soapcommand = "account lock on";
			}
			elseif ($option['delete'] == "delete") {
				$soapcommand = "account delete " . $user['username'];
			}
			//if $soapcommand not empty, execute
			if (!empty($soapcommand)){
				$result = $client->executeCommand(new SoapParam($soapcommand, 'command'));
				JFactory::getApplication()->enqueueMessage($result);
			}
			
		}
	}
}
?>
