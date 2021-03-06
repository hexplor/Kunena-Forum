<?php
/**
 * Kunena Component
 * @package Kunena.Administrator
 * @subpackage Controllers
 *
 * @copyright (C) 2008 - 2014 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

jimport('joomla.filesystem.archive');

/**
 * Kunena Backend Templates Controller
 *
 * @since 2.0
 */
class KunenaAdminControllerTemplates extends KunenaController {
	protected $baseurl = null;
	protected $locked = array('blue_eagle');

	public function __construct($config = array()) {
		parent::__construct($config);
		$this->baseurl = 'administrator/index.php?option=com_kunena&view=templates';
	}

	function publish() {
		$cid	= JRequest::getVar('cid', array(), 'method', 'array');
		$id = array_shift($cid);

		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		if ($id) {
			$this->config->template = $id;
			$this->config->save ();
		}
		$template = KunenaFactory::getTemplate($id);
		$template->clearCache();

		$this->app->enqueueMessage ( JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_DEFAULT_SELECTED'));
		$this->setRedirect(KunenaRoute::_($this->baseurl, false));
	}

	function add() {
		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		$this->setRedirect(KunenaRoute::_($this->baseurl."&layout=add", false));
	}

	function edit() {
		$cid	= JRequest::getVar('cid', array(), 'method', 'array');
		$template = array_shift($cid);

		if (!$template) {
			JError::raiseWarning( 500, JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE_NOT_SPECIFIED') );
			return;
		}
		$tBaseDir = KunenaPath::clean(KPATH_SITE.'/template');
		if (!is_dir( $tBaseDir . '/' . $template )) {
			JError::raiseWarning( 500, JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE_NOT_FOUND') );
			return;
		}

		$template = KunenaPath::clean($template);
		$this->app->setUserState ( 'kunena.edit.template', $template);

		$this->setRedirect(KunenaRoute::_($this->baseurl."&layout=edit&name={$template}", false));
	}

	function install() {
		$tmp = JPATH_ROOT . '/tmp/kinstall/';
		$dest = KPATH_SITE . '/template/';
		$file = JRequest::getVar ( 'install_package', NULL, 'FILES', 'array' );

		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		if (!$file || !is_uploaded_file ( $file ['tmp_name'])) {
			$this->app->enqueueMessage ( JText::sprintf('COM_KUNENA_A_TEMPLATE_MANAGER_INSTALL_EXTRACT_MISSING', $file ['name']), 'notice' );
		}
		else {
			$success = KunenaFile::upload($file ['tmp_name'], $tmp . $file ['name']);
			if ($success) $success = JArchive::extract ( $tmp . $file ['name'], $tmp );
			if (! $success) {
				$this->app->enqueueMessage ( JText::sprintf('COM_KUNENA_A_TEMPLATE_MANAGER_INSTALL_EXTRACT_FAILED', $file ['name']), 'notice' );
			}
			// Delete the tmp install directory
			if (is_dir($tmp)) {
				$templates = KunenaTemplateHelper::parseXmlFiles($tmp);
				if (!empty($templates)) {
					foreach ($templates as $template) {
						// Never overwrite locked templates
						if (in_array($template->directory, $this->locked)) continue;
						if (is_dir($dest.$template->directory)) {
							if (is_file($dest.$template->directory.'/params.ini')) {
								if (is_file($tmp.$template->sourcedir.'/params.ini')) {
									KunenaFile::delete($tmp.$template->sourcedir.'/params.ini');
								}
								KunenaFile::move($dest.$template->directory.'/params.ini', $tmp.$template->sourcedir.'/params.ini');
							}
							KunenaFolder::delete($dest.$template->directory);
						}
						$success = KunenaFolder::move($tmp.$template->sourcedir, $dest.$template->directory);
						if ($success !== true) {
							$this->app->enqueueMessage ( JText::sprintf('COM_KUNENA_A_TEMPLATE_MANAGER_INSTALL_FAILED', $template->directory), 'notice' );
						} else {
							$this->app->enqueueMessage(JText::sprintf('COM_KUNENA_A_TEMPLATE_MANAGER_INSTALL_SUCCESS', $template->directory));
						}
					}
					if (is_dir($tmp)) KunenaFolder::delete($tmp);
					// Clear all cache, just in case.
					KunenaCacheHelper::clearAll();
				} else {
					JError::raiseWarning(100, JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE_MISSING_FILE'));
				}
			} else {
				JError::raiseWarning(100, JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE').' '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_UNINSTALL').': '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_DIR_NOT_EXIST'));
			}
		}
		$this->setRedirect(KunenaRoute::_($this->baseurl, false));
	}

	function uninstall() {
		$cid	= JRequest::getVar('cid', array(), 'method', 'array');
		$id = array_shift($cid);
		$template	= $id;

		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		// Initialize variables
		$otemplate = KunenaTemplateHelper::parseXmlFile($id);
		if ( !$otemplate ) {
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE_NOT_SPECIFIED'), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}
		if ( in_array($id, $this->locked) ) {
			$this->app->enqueueMessage ( JText::sprintf('COM_KUNENA_A_CTRL_TEMPLATES_ERROR_UNINSTALL_SYSTEM_TEMPLATE', $otemplate->name), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}
		if ( KunenaTemplateHelper::isDefault($template) ) {
			$this->app->enqueueMessage ( JText::sprintf('COM_KUNENA_A_CTRL_TEMPLATES_ERROR_UNINSTALL_DEFAULT_TEMPLATE', $otemplate->name), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}
		$tpl = KPATH_SITE . '/template/'.$template;
		// Delete the template directory
		if (is_dir($tpl)) {
			$retval = KunenaFolder::delete($tpl);
			// Clear all cache, just in case.
			KunenaCacheHelper::clearAll();
			$this->app->enqueueMessage ( JText::sprintf('COM_KUNENA_A_TEMPLATE_MANAGER_UNINSTALL_SUCCESS', $id) );
		} else {
			JError::raiseWarning(100, JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE').' '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_UNINSTALL').': '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_DIR_NOT_EXIST'));
			$retval = false;
		}
		$this->setRedirect(KunenaRoute::_($this->baseurl, false));
	}

	function choosecss() {
		$template	= JRequest::getVar('id', '', 'method', 'cmd');
		$this->app->setUserState ( 'kunena.choosecss', $template );

		$this->setRedirect(KunenaRoute::_($this->baseurl."&layout=choosecss", false));
	}

	function editcss() {
		$template	= JRequest::getVar('id', '', 'method', 'cmd');
		$filename	= JRequest::getVar('filename', '', 'method', 'cmd');

		if (KunenaFile::getExt($filename) !== 'css') {
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_WRONG_CSS'));
			$this->setRedirect(KunenaRoute::_($this->baseurl.'&layout=choosecss&id='.$template, false));
		}

		$this->app->setUserState ( 'kunena.editcss.tmpl', $template );
		$this->app->setUserState ( 'kunena.editcss.filename', $filename );

		$this->setRedirect(KunenaRoute::_($this->baseurl."&layout=editcss", false));
	}

	function savecss() {
		$template		= JRequest::getVar('id', '', 'post', 'cmd');
		$filename		= JRequest::getVar('filename', '', 'post', 'cmd');
		$filecontent	= JRequest::getVar('filecontent', '', 'post', 'string', JREQUEST_ALLOWRAW);

		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		if (!$template) {
			$this->app->enqueueMessage (JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_OPERATION_FAILED').': '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE_NOT_SPECIFIED.'));
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}
		if (!$filecontent) {
			$this->app->enqueueMessage (JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_OPERATION_FAILED').': '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_CONTENT_EMPTY'));
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}
		// Set FTP credentials, if given
		JClientHelper::setCredentialsFromRequest('ftp');
		$ftp = JClientHelper::getCredentials('ftp');
		$file = KPATH_SITE.'/template/'.$template.'/css/'.$filename;
		if (!$ftp['enabled'] && KunenaPath::isOwner($file) && !KunenaPath::setPermissions($file, '0755')) {
			JError::raiseNotice('SOME_ERROR_CODE', JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_COULD_NOT_CSS_WRITABLE'));
		}
		$return = KunenaFile::write($file, $filecontent);
		if (!$ftp['enabled'] && KunenaPath::isOwner($file) && !KunenaPath::setPermissions($file, '0555')) {
			JError::raiseNotice('SOME_ERROR_CODE', JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_COULD_NOT_CSS_UNWRITABLE'));
		}
		if ($return) {
			$this->app->enqueueMessage (JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_FILE_SAVED'));
			$this->setRedirect(KunenaRoute::_($this->baseurl."&layout=edit&cid[]='.$template", false));
		} else {
			$this->app->enqueueMessage (JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_OPERATION_FAILED').': '.JText::sprintf('COM_KUNENA_A_TEMPLATE_MANAGER_FAILED_OPEN_FILE.', $file));
			$this->setRedirect(KunenaRoute::_($this->baseurl.'&layout=choosecss&id='.$template, false));
		}
	}

	function apply() {
		$template= JRequest::getVar('templatename', '', 'method', 'cmd');
		$menus = JRequest::getVar('selections', array(), 'post', 'array');
		JArrayHelper::toInteger($menus);

		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		if (!$template) {
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_OPERATION_FAILED').': '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE_NOT_SPECIFIED'));
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		$this->_saveParamFile($template);

		$this->app->enqueueMessage (JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_CONFIGURATION_SAVED'));
		$this->setRedirect(KunenaRoute::_($this->baseurl.'&layout=edit&cid[]='.$template, false));
	}

	function save() {
		$template= JRequest::getVar('templatename', '', 'method', 'cmd');
		$menus= JRequest::getVar('selections', array(), 'post', 'array');
		JArrayHelper::toInteger($menus);

		if (! JSession::checkToken('post')) {
			$this->app->enqueueMessage ( JText::_ ( 'COM_KUNENA_ERROR_TOKEN' ), 'error' );
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		if (!$template) {
			$this->app->enqueueMessage ( JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_OPERATION_FAILED').': '.JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_TEMPLATE_NOT_SPECIFIED'));
			$this->setRedirect(KunenaRoute::_($this->baseurl, false));
			return;
		}

		$this->_saveParamFile($template);

		$this->app->enqueueMessage (JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_CONFIGURATION_SAVED'));
		$this->setRedirect(KunenaRoute::_($this->baseurl, false));
	}

	/**
	 * Method to save param.ini file on filesystem.
	 *
	 * @param   string  $template  The name of the template.
	 *
	 *
	 * @since	3.0.0
	 */
	protected function _saveParamFile($template) {
		$params= JRequest::getVar('jform', array(), 'post', 'array');

		// Set FTP credentials, if given
		JClientHelper::setCredentialsFromRequest('ftp');
		$ftp = JClientHelper::getCredentials('ftp');
		$file = KPATH_SITE.'/template/'.$template.'/params.ini';
		if ( count($params) ) {
			$registry = new JRegistry();
			$registry->loadArray($params);
			$txt = $registry->toString('INI');
			$return = KunenaFile::write($file, $txt);
			if (!$return) {
				$this->app->enqueueMessage ( JText::_('COM_KUNENA_A_TEMPLATE_MANAGER_OPERATION_FAILED').': '.JText::sprintf('COM_KUNENA_A_TEMPLATE_MANAGER_FAILED_WRITE_FILE', $file));
				$this->app->redirect ( KunenaRoute::_($this->baseurl, false) );
			}
		}
	}

	/**
	 * Method to just redirect to main manager in case of use of cancel button
	 *
	 * @return void
	 *
	 * @since 3.0.5
	 */
	public function cancel()
	{
		$this->app->redirect(KunenaRoute::_($this->baseurl, false));
	}
}
