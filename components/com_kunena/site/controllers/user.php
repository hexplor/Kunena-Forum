<?php
/**
 * Kunena Component
 * @package Kunena.Site
 * @subpackage Controllers
 *
 * @copyright (C) 2008 - 2013 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

/**
 * Kunena User Controller
 *
 * @since		2.0
 */
class KunenaControllerUser extends KunenaController {
	public function display($cachable = false, $urlparams = false) {
		// Redirect profile to integrated component if profile integration is turned on
		$redirect = 1;
		$active = $this->app->getMenu ()->getActive ();

		if (!empty($active)) {
			$params = $active->params;
			$redirect = $params->get('integration', 1);
		}
		if ($redirect && JRequest::getCmd('format', 'html') == 'html') {
			$profileIntegration = KunenaFactory::getProfile();
			$layout = JRequest::getCmd('layout', 'default');
			if ($profileIntegration instanceof KunenaProfileKunena) {
				// Continue
			} elseif ($layout == 'default') {
				$url = $this->me->getUrl(false);
			} elseif ($layout == 'list') {
				$url = $profileIntegration->getUserListURL('', false);
			}
			if (!empty($url)) {
				$this->setRedirect($url);
				return;
			}
		}
		parent::display();
	}

	public function change() {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$layout = JRequest::getString ( 'topic_layout', 'default' );
		$this->me->setTopicLayout ( $layout );
		$this->setRedirectBack();
	}

	public function karmaup() {
		$this->karma(1);
	}

	public function karmadown() {
		$this->karma(-1);
	}

	public function save() {
		// TODO: allow moderators to save another users profile (without account info)
		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage (JText::_ ('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();
			return;
		}

		// Make sure that the user exists.
		if (!$this->me->exists()) {
			JError::raiseError(403, JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'));
			$this->setRedirectBack();
			return;
		}

		// Set default redirect.
		$return = base64_decode(JRequest::getVar('return', '', 'method', 'base64'));
		if ($return && JURI::isInternal($return)) {
			$this->setRedirect(JRoute::_($return, false));
		} else {
			$this->setRedirect($this->me->getURL(false));
		}

		// Define error redirect.
		$err_return = KunenaRoute::getReferrer('index.php?option=com_kunena&view=user&layout=edit');

		$this->user = JFactory::getUser();

		// Save Joomla user.
		$success = $this->saveUser();

		if (!$success) {
			$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_ACCOUNT_NOT_SAVED'), 'error');
			$this->setRedirect($err_return);
		}

		// Save avatar.
		$success = $this->saveAvatar();

		if (!$success) {
			$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_AVATAR_NOT_SAVED'), 'error');
			$this->setRedirect($err_return);
		}

		// Save Kunena user.
		$this->saveProfile();
		$this->saveSettings();
		$success = $this->me->save();
		if (!$success) {
			$this->app->enqueueMessage($this->me->getError(), 'error');
			$this->setRedirect($err_return);
		}

		if ($this->redirect != $err_return) {
			$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_SAVED'));
		}

		JPluginHelper::importPlugin('system');
		// TODO: Rename into JEventDispatcher when dropping Joomla! 2.5 support
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('OnAfterKunenaProfileUpdate', array($this->me, $success));
	}

	function ban() {
		$user = KunenaFactory::getUser(JRequest::getInt ( 'userid', 0 ));
		if(!$user->exists() || !JSession::checkToken('post')) {
			$this->setRedirect($user->getUrl(false), JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			return;
		}
		$ban = KunenaUserBan::getInstanceByUserid($user->userid, true);
		if (!$ban->canBan()) {
			$this->setRedirect($user->getUrl(false), $ban->getError(), 'error');
			return;
		}

		$ip = JRequest::getString ( 'ip', '' );
		$block = JRequest::getInt ( 'block', 0 );
		$expiration = JRequest::getString ( 'expiration', '' );
		$reason_private = JRequest::getString ( 'reason_private', '' );
		$reason_public = JRequest::getString ( 'reason_public', '' );
		$comment = JRequest::getString ( 'comment', '' );

		if (! $ban->id) {
			$ban->ban ( $user->userid, $ip, $block, $expiration, $reason_private, $reason_public, $comment );
			$success = $ban->save ();
			$this->report($user->userid);
		} else {
			$delban = JRequest::getString ( 'delban', '' );

			if ( $delban ) {
				$ban->unBan($comment);
				$success = $ban->save ();
			} else {
				$ban->blocked = $block;
				$ban->setExpiration ( $expiration, $comment );
				$ban->setReason ( $reason_public, $reason_private );
				$success = $ban->save ();
			}
		}

		if ($block) {
			if ($ban->isEnabled ())
				$message = JText::_ ( 'COM_KUNENA_USER_BLOCKED_DONE' );
			else
				$message = JText::_ ( 'COM_KUNENA_USER_UNBLOCKED_DONE' );
		} else {
			if ($ban->isEnabled ())
				$message = JText::_ ( 'COM_KUNENA_USER_BANNED_DONE' );
			else
				$message = JText::_ ( 'COM_KUNENA_USER_UNBANNED_DONE' );
		}

		if (! $success) {
			$this->app->enqueueMessage ( $ban->getError (), 'error' );
		} else {
			$this->app->enqueueMessage ( $message );
		}

		$banDelPosts = JRequest::getVar ( 'bandelposts', '' );
		$DelAvatar = JRequest::getVar ( 'delavatar', '' );
		$DelSignature = JRequest::getVar ( 'delsignature', '' );
		$DelProfileInfo = JRequest::getVar ( 'delprofileinfo', '' );

		if (! empty ( $DelAvatar ) || ! empty ( $DelProfileInfo )) {
			$avatar_deleted = '';
			// Delete avatar from file system
			if (is_file(JPATH_ROOT . '/media/kunena/avatars/' . $user->avatar ) && !stristr($user->avatar,'gallery/')) {
				KunenaFile::delete ( JPATH_ROOT . '/media/kunena/avatars/' . $user->avatar );
				$avatar_deleted = JText::_('COM_KUNENA_MODERATE_DELETED_BAD_AVATAR_FILESYSTEM');
			}
			$user->avatar = '';
			$user->save();
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_MODERATE_DELETED_BAD_AVATAR') . $avatar_deleted );
		}
		if (! empty ( $DelProfileInfo )) {
			$user->personalText = '';
			$user->birthdate = '0000-00-00';
			$user->location = '';
			$user->gender = 0;
			$user->icq = '';
			$user->aim = '';
			$user->yim = '';
			$user->msn = '';
			$user->skype = '';
			$user->gtalk = '';
			$user->twitter = '';
			$user->facebook = '';
			$user->myspace = '';
			$user->linkedin = '';
			$user->delicious = '';
			$user->friendfeed = '';
			$user->digg = '';
			$user->blogspot = '';
			$user->flickr = '';
			$user->bebo = '';
			$user->websitename = '';
			$user->websiteurl = '';
			$user->signature = '';
			$user->save();
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_MODERATE_DELETED_BAD_PROFILEINFO') );
		} elseif (! empty ( $DelSignature )) {
			$user->signature = '';
			$user->save();
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_MODERATE_DELETED_BAD_SIGNATURE') );
		}

		if (! empty ( $banDelPosts )) {
			list($total, $messages) = KunenaForumMessageHelper::getLatestMessages(false, 0, 0, array('starttime'=> '-1','user' => $user->userid));
			foreach($messages as $mes) {
				$mes->publish(KunenaForum::DELETED);
			}
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_MODERATE_DELETED_BAD_MESSAGES') );
		}

		$this->setRedirect($user->getUrl(false));
	}

	function cancel() {
		$user = KunenaFactory::getUser();
		$this->setRedirect($user->getUrl(false));
	}

	function login() {
		if(!JFactory::getUser()->guest || !JSession::checkToken('post')) {
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();
			return;
		}

		$username = JRequest::getString ( 'username', '', 'POST' );
		$password = JRequest::getString ( 'password', '', 'POST', JREQUEST_ALLOWRAW );
		$remember = JRequest::getBool ( 'remember', false, 'POST');

		$login = KunenaLogin::getInstance();
		$error = $login->loginUser($username, $password, $remember);

		// Get the return url from the request and validate that it is internal.
		$return = base64_decode(JRequest::getVar('return', '', 'method', 'base64'));
		if (!$error && $return && JURI::isInternal($return))
		{
			// Redirect the user.
			$this->setRedirect(JRoute::_($return, false));
			return;
		}

		$this->setRedirectBack();
	}

	function logout() {
		if(!JSession::checkToken('request')) {
			$this->app->enqueueMessage(JText::_('COM_KUNENA_ERROR_TOKEN'), 'error');
			$this->setRedirectBack();
			return;
		}

		$login = KunenaLogin::getInstance();
		if (!JFactory::getUser()->guest) $login->logoutUser();

		// Get the return url from the request and validate that it is internal.
		$return = base64_decode(JRequest::getVar('return', '', 'method', 'base64'));
		if ($return && JURI::isInternal($return))
		{
			// Redirect the user.
			$this->setRedirect(JRoute::_($return, false));
			return;
		}

		$this->setRedirectBack();
	}

	// Internal functions:

	protected function karma($karmaDelta) {
		if (! JSession::checkToken ('get')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}
		$karma_delay = '14400'; // 14400 seconds = 6 hours
		$userid = JRequest::getInt ( 'userid', 0 );

		$target = KunenaFactory::getUser($userid);

		if (!$this->config->showkarma || !$this->me->exists() || !$target->exists() || $karmaDelta == 0) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_USER_ERROR_KARMA' ), 'error' );
			$this->setRedirectBack();
			return;
		}

		$now = JFactory::getDate()->toUnix();
		if (!$this->me->isModerator() && $now - $this->me->karma_time < $karma_delay) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_KARMA_WAIT' ), 'notice' );
			$this->setRedirectBack();
			return;
		}

		if ($karmaDelta > 0) {
			if ($this->me->userid == $target->userid) {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_KARMA_SELF_INCREASE' ), 'notice' );
				$karmaDelta = -10;
			} else {
				$this->app->enqueueMessage ( JText::_('COM_KUNENA_KARMA_INCREASED' ) );
			}
		} else {
			if ($this->me->userid == $target->userid) {
				$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_KARMA_SELF_DECREASE' ), 'notice' );
			} else {
				$this->app->enqueueMessage ( JText::_('COM_KUNENA_KARMA_DECREASED' ) );
			}
		}

		$this->me->karma_time = $now;
		if ($this->me->userid != $target->userid && !$this->me->save()) {
			$this->app->enqueueMessage($this->me->getError(), 'notice');
			$this->setRedirectBack();
			return;
		}
		$target->karma += $karmaDelta;
		if (!$target->save()) {
			$this->app->enqueueMessage($target->getError(), 'notice');
			$this->setRedirectBack();
			return;
		}
		// Activity integration
		$activity = KunenaFactory::getActivityIntegration();
		$activity->onAfterKarma($target->userid, $this->me->userid, $karmaDelta);
		$this->setRedirectBack();
	}

	// Mostly copied from Joomla 1.5
	protected function saveUser() {
		// we only allow users to edit few fields
		$allow = array('name', 'email', 'password', 'password2', 'params');
		if (JComponentHelper::getParams('com_users')->get('change_login_name', 1)) $allow[] = 'username';

		//clean request
		$post = JRequest::get('post');
		$post['password']	= JRequest::getVar('password', '', 'post', 'string', JREQUEST_ALLOWRAW);
		$post['password2']	= JRequest::getVar('password2', '', 'post', 'string', JREQUEST_ALLOWRAW);
		if (empty($post['password']) || empty($post['password2'])) {
			unset($post['password'], $post['password2']);
		} else {
			// Do a password safety check.
			if($post['password'] != $post['password2']) {
				$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_PASSWORD_MISMATCH'), 'notice');
				return false;
			}
			if(strlen($post['password']) < 5) {
				$this->app->enqueueMessage(JText::_('COM_KUNENA_PROFILE_PASSWORD_NOT_MINIMUM'), 'notice');
				return false;
			}
		}
		$post = array_intersect_key($post, array_flip($allow));
		if (empty($post)) return true;

		$username = $this->user->get('username');

		$user = new JUser($this->user->id);
		// Bind the form fields to the user table and save.
		if (!($user->bind($post) && $user->save(true))) {
			$this->app->enqueueMessage($user->getError(), 'notice');
			return false;
		}

		// Reload the user.
		$this->user->load($this->user->id);
		$session = JFactory::getSession();
		$session->set('user', $this->user);

		// update session if username has been changed
		if ($username && $username != $this->user->username) {
			$table = JTable::getInstance('session', 'JTable');
			$table->load($session->getId());
			$table->username = $this->user->username;
			$table->store();
		}
		return true;
	}

	protected function saveProfile() {
		$this->me->personalText = JRequest::getVar ( 'personaltext', '' );
		$birthdate = JRequest::getString('birthdate');
		if (!$birthdate) {
			$birthdate = JRequest::getInt('birthdate1', '0000').'-'.JRequest::getInt('birthdate2', '00').'-'.JRequest::getInt ('birthdate3', '00');
		}
		$this->me->birthdate = $birthdate;
		$this->me->location = trim(JRequest::getVar ( 'location', '' ));
		$this->me->gender = JRequest::getInt ( 'gender', '' );
		$this->me->icq = trim(JRequest::getString ( 'icq', '' ));
		$this->me->aim = trim(JRequest::getString ( 'aim', '' ));
		$this->me->yim = trim(JRequest::getString ( 'yim', '' ));
		$this->me->msn = trim(JRequest::getString ( 'msn', '' ));
		$this->me->skype = trim(JRequest::getString ( 'skype', '' ));
		$this->me->gtalk = trim(JRequest::getString ( 'gtalk', '' ));
		$this->me->twitter = trim(JRequest::getString ( 'twitter', '' ));
		$this->me->facebook = trim(JRequest::getString ( 'facebook', '' ));
		$this->me->myspace = trim(JRequest::getString ( 'myspace', '' ));
		$this->me->linkedin = trim(JRequest::getString ( 'linkedin', '' ));
		$this->me->delicious = trim(JRequest::getString ( 'delicious', '' ));
		$this->me->friendfeed = trim(JRequest::getString ( 'friendfeed', '' ));
		$this->me->digg = trim(JRequest::getString ( 'digg', '' ));
		$this->me->blogspot = trim(JRequest::getString ( 'blogspot', '' ));
		$this->me->flickr = trim(JRequest::getString ( 'flickr', '' ));
		$this->me->bebo = trim(JRequest::getString ( 'bebo', '' ));
		$this->me->websitename = JRequest::getString ( 'websitename', '' );
		$this->me->websiteurl = JRequest::getString ( 'websiteurl', '' );
		$this->me->signature = JRequest::getVar ( 'signature', '', 'post', 'string', JREQUEST_ALLOWRAW );
	}

	protected function saveAvatar() {
		$action = JRequest::getString('avatar', 'keep');
		$current_avatar = $this->me->avatar;

		require_once (KPATH_SITE.'/lib/kunena.upload.class.php');
		$upload = new CKunenaUpload();
		$upload->setAllowedExtensions('gif, jpeg, jpg, png');

		if ( $upload->uploaded('avatarfile') ) {
			$filename = 'avatar'.$this->me->userid;

			if (preg_match('|^users/|' , $this->me->avatar)) {
				// Delete old uploaded avatars:
				if (is_dir( KPATH_MEDIA.'/avatars/resized')) {
					$deletelist = KunenaFolder::folders(KPATH_MEDIA.'/avatars/resized', '.', false, true);
					foreach ($deletelist as $delete) {
						if (is_file($delete.'/'.$this->me->avatar))
							KunenaFile::delete($delete.'/'.$this->me->avatar);
					}
				}
				if (is_file(KPATH_MEDIA.'/avatars/'.$this->me->avatar)) {
					KunenaFile::delete(KPATH_MEDIA.'/avatars/'.$this->me->avatar);
				}
			}

			$upload->setImageResize(intval($this->config->avatarsize)*1024, 200, 200, $this->config->avatarquality);
			$upload->uploadFile(KPATH_MEDIA . '/avatars/users' , 'avatarfile', $filename, false);
			$fileinfo = $upload->getFileInfo();

			if ($fileinfo['ready'] === true) {
				$this->me->avatar = 'users/'.$fileinfo['name'];
			}
			if (!$fileinfo['status']) {
				$this->me->avatar = $current_avatar;
				if (!$fileinfo['not_valid_img_ext']) $this->app->enqueueMessage ( JText::sprintf ( 'COM_KUNENA_UPLOAD_FAILED', $fileinfo['name']).': '.JText::sprintf('COM_KUNENA_AVATAR_UPLOAD_NOT_VALID_EXTENSIONS', 'gif, jpeg, jpg, png'), 'error' );
				else $this->app->enqueueMessage ( JText::sprintf ( 'COM_KUNENA_UPLOAD_FAILED', $fileinfo['name']).': '.$fileinfo['error'], 'error' );
				return false;
			} else {
				$this->app->enqueueMessage ( JText::sprintf ( 'COM_KUNENA_PROFILE_AVATAR_UPLOADED' ) );
			}
		} else if ( $action == 'delete' ) {
			//set default avatar
			$this->me->avatar = '';
		} else if ( substr($action, 0, 8) == 'gallery/' && strpos($action, '..') === false) {
			$this->me->avatar = $action;
		}
		return true;
	}

	protected function saveSettings() {
		$this->me->ordering = JRequest::getInt('messageordering', '', 'post', 'messageordering');
		$this->me->hideEmail = JRequest::getInt('hidemail', '', 'post', 'hidemail');
		$this->me->showOnline = JRequest::getInt('showonline', '', 'post', 'showonline');
	}

	// Reports a user to stopforumspam.com
	protected function report($userid) {
		if(!$this->config->stopforumspam_key || ! $userid)
		{
			return false;
		}
		$spammer = JFactory::getUser($userid);

		$db = JFactory::getDBO();
		$db->setQuery ( "SELECT ip FROM #__kunena_messages WHERE userid=".$userid." GROUP BY ip ORDER BY `time` DESC", 0, 1 );
		$ip = $db->loadResult();

		// TODO: replace this code by using JHttpTransport class
		$data = "username=".$spammer->username."&ip_addr=".$ip."&email=".$spammer->email."&api_key=".$this->config->stopforumspam_key;
		$fp = fsockopen("www.stopforumspam.com",80);
		fputs($fp, "POST /add.php HTTP/1.1\n" );
		fputs($fp, "Host: www.stopforumspam.com\n" );
		fputs($fp, "Content-type: application/x-www-form-urlencoded\n" );
		fputs($fp, "Content-length: ".strlen($data)."\n" );
		fputs($fp, "Connection: close\n\n" );
		fputs($fp, $data);
		// Create a buffer which holds the response
		$response = '';
		// Read the response
		while (!feof($fp))
		{
			$response .= fread($fp, 1024);
		}
		// The file pointer is no longer needed. Close it
		fclose($fp);

		if (strpos($response, 'HTTP/1.1 200 OK') === 0)
		{
			// Report accepted. There is no need to display the reason
			$this->app->enqueueMessage(JText::_('COM_KUNENA_STOPFORUMSPAM_REPORT_SUCCESS'));
			return true;
		}
		else
		{
			// Report failed or refused
			$reasons = array();
			preg_match('/<p>.*<\/p>/', $response, $reasons);
			// stopforumspam returns only one reason, which is reasons[0], but we need to strip out the html tags before using it
			$this->app->enqueueMessage(JText::sprintf('COM_KUNENA_STOPFORUMSPAM_REPORT_FAILED', strip_tags($reasons[0])),'error');
			return false;
		}
	}

	public function delfile() {
		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirectBack();
			return;
		}
		$cids = JRequest::getVar ( 'cid', array (), 'post', 'array' );

		if ( !empty($cids) ) {
			$number = 0;

			foreach( $cids as $id ) {
				$attachment = KunenaForumMessageAttachmentHelper::get($id);
				if ($attachment->authorise('delete') && $attachment->delete()) $number++;
			}

			if ( $number > 0 ) {
				$this->app->enqueueMessage ( JText::sprintf( 'COM_KUNENA_ATTACHMENTS_DELETE_SUCCESSFULLY', $number) );
				$this->setRedirectBack();
				return;
			} else {
				$this->app->enqueueMessage ( JText::_( 'COM_KUNENA_ATTACHMENTS_DELETE_FAILED') );
				$this->setRedirectBack();
				return;
			}
		}

		$this->app->enqueueMessage ( JText::_( 'COM_KUNENA_ATTACHMENTS_NO_ATTACHMENTS_SELECTED') );
		$this->setRedirectBack();
	}
}
