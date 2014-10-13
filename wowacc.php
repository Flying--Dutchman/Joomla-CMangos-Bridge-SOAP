<?php
/**
 * @version    $Id: myauth.php 7180 2007-04-23 16:51:53Z jinx $
 * @package    Joomla.Tutorials
 * @subpackage Plugins
 * @license    GNU/GPL
 */
 
// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();
 
class plgUserWowacc extends JPlugin
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
			//new password --> encrypt it
			$wowuser = $new['username'];  
			$wowpass = sha1(strtoupper($wowuser . ":" . $pass));
		}
		else {
			$wowpass = '';
		}
		//check if a new email was set
		if ($user['email'] == $new['email']) {
			//new password --> encrypt it
			$newmail = false;
		}
		//save hashed password in new session variable
		$session = JFactory::getSession();
		$session->set('wowpass', $wowpass); 
		$session->set('newmail', $newmail); 
		
    }
    function onUserAfterSave($user, $isnew, $success, $msg)
    {	
		//Check if user was successfully stored in the database
		if (!$success){
			JFactory::getApplication()->enqueueMessage('User was not stored/altered in the WoW-Database since it was not stored/altered in the Joomla Database');
		}
		else {
			//Load settings
			$option = array(); 
			$option['driver']   		= $this->params->get('mysql-driver');          
			$option['host']     		= $this->params->get('mysql-host');  
			$option['user']     		= $this->params->get('mysql-user');       
			$option['password'] 		= $this->params->get('mysql-pass');   
			$option['database'] 		= $this->params->get('mysql-database');      
			$option['dbprefix'] 		= $this->params->get('mysql-dbprefix');
			$option['id-mod'] 			= $this->params->get('id-mod');   
			$option['id-gm'] 			= $this->params->get('id-gm');      
			$option['id-admin'] 		= $this->params->get('id-admin'); 
			$option['id-ignore-group'] 	= $this->params->get('id-ignore-group'); 
			$option['id-ignore-user'] 	= $this->params->get('id-ignore-user');
			//Load new Values (saved in Session in onUserBeforeSave)
			$session = JFactory::getSession();
			$wowpass = $session->get('wowpass');
			$newmail = $session->get('newmail');
			$wowmail = $user['email'];
			$wowuser = $user['username'];
			//Get Databasesession
			$db = JDatabaseDriver::getInstance($option);  
			$query = $db->getQuery(true);
			//SQL-Settings
			$set_val = array();
			$gmlvl = 0;
			$modgmlvl = false;
			
			//New account or existing one being altered?
			if ($isnew) {
				$query
					->insert($db->quoteName('account'))
					->columns(array('username', 'sha_pass_hash', 'email', 'gmlevel'));
				$set_val[0] = "'$wowuser', '$wowpass', '$wowmail',";
			}		
			else {
				//Any changes to email or password?
				if ((empty($wowpass)) && (!$newmail)){
					//no changes made
					return;
				}
				//Is a new password set?
				if (empty($wowpass)){
					//password is empty, so password hasn't changed
					$query
						->update($db->quoteName('account')) 
						->where(array($db->quoteName('username') . '=' . "'" . $user['username'] . "'"));  
					array_push($set_val, $db->quoteName('email') . '=' . "'$wowmail'");
				}
				else {
					//password isn't empty, so password was altered
					$query
						->update($db->quoteName('account'))
						->where(array($db->quoteName('username') . '=' . "'" . $user['username'] . "'"));
					array_push($set_val, $db->quoteName('sha_pass_hash') . '=' . "'$wowpass'", $db->quoteName('email') . '=' . "'$wowmail'", $db->quoteName('v') . "=''", $db->quoteName('s') . "=''" );
				}
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
			if ($isnew) {
				$set_val[0] .= "'$gmlvl'"; 
				$query->values($set_val[0]);
			}
			else {
				if ($modgmlvl) {
					array_push($set_val, $db->quoteName('gmlevel') . "='$gmlvl'");
				}
				$query->set($set_val);
			}
			
			$db->setQuery($query); 
			$db->execute();
			
			//Block, Delete user?
			if (($this->params->get('joomlablock') == 'on') && $user['block'] ) {
				$this->onUserAfterDelete($user, true, $msg);
			}
			elseif (($this->params->get('wowenable') == 'on') && !$user['block']) {
				$query
					->clear()
					->update($db->quoteName('account')) 
					->set(array($db->quoteName('locked') . "='0'"))
					->where(array($db->quoteName('username') . '=' . "'" . $user['username'] . "'")); 
				$db->setQuery($query); 
				$db->execute();	
			}
		}
    }

	function onUserAfterDelete($user, $success, $msg ) {
		//Load settings
		$option = array(); 
		$option['driver']   		= $this->params->get('mysql-driver');          
		$option['host']     		= $this->params->get('mysql-host');  
		$option['user']     		= $this->params->get('mysql-user');       
		$option['password'] 		= $this->params->get('mysql-pass');   
		$option['database'] 		= $this->params->get('mysql-database');      
		$option['dbprefix'] 		= $this->params->get('mysql-dbprefix');
		$option['lock'] 			= $this->params->get('wowlock');   

		//Get Databasesession
		$db = JDatabaseDriver::getInstance($option);  
		$query = $db->getQuery(true);
		
		if ($success) {
			//Lock or Delete WoW-Account?
			if ($option['lock'] == "lock") {
				$query
					->update($db->quoteName('account')) 
					->set(array($db->quoteName('locked') . "='1'"))
					->where(array($db->quoteName('username') . '=' . "'" . $user['username'] . "'"));  
			}
			elseif ($option['delete'] == "delete") {
				$query
					->delete($db->quoteName('account'))
					->where(array($db->quoteName('username') . '=' . "'" . $user['username'] . "'"));
			}
			else {
				//Plausi
				return;
			}
			
			$db->setQuery($query); 
			$db->execute();
		}
	}
}
?>
