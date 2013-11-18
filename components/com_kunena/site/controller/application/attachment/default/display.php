<?php
/**
 * Kunena Component
 * @package     Kunena.Site
 * @subpackage  Controller.Application
 *
 * @copyright   (C) 2008 - 2013 Kunena Team. All rights reserved.
 * @license     http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link        http://www.kunena.org
 **/
defined('_JEXEC') or die;

/**
 * Class ComponentKunenaControllerApplicationAttachmentDefaultDisplay
 *
 * Only implemented on raw format as it's faster to run.
 *
 * @since  3.1
 */
class ComponentKunenaControllerApplicationAttachmentDefaultDisplay extends KunenaControllerApplicationDisplay
{
	/**
	 * Return true if layout exists.
	 *
	 * @return bool
	 */
	public function exists()
	{
		return true;
	}

	/**
	 * Display attachment.
	 *
	 * @return void
	 *
	 * @throws RuntimeException
	 * @throws KunenaExceptionAuthorise
	 */
	public function execute()
	{
		$format = $this->input->getWord('format', 'html');
		$id = $this->input->getInt('id', 0);
		$thumb = $this->input->getBool('thumb', false);
		$download = $this->input->getBool('download', false);

		// Run before executing action.
		$result = $this->before();

		if ($result === false || $format != 'raw' || !$id)
		{
			throw new RuntimeException(JText::_('COM_KUNENA_NO_ACCESS'), 404);
		}
		elseif ($this->config->board_offline && !$this->me->isAdmin())
		{
			// Forum is offline.
			throw new RuntimeException(JText::_('COM_KUNENA_FORUM_IS_OFFLINE'), 503);
		}
		elseif ($this->config->regonly && !$this->me->exists())
		{
			// Forum is for registered users only.
			throw new RuntimeException(JText::_('COM_KUNENA_LOGIN_NOTIFICATION'), 403);
		}

		$attachment = KunenaForumMessageAttachmentHelper::get($id);
		$me = KunenaUserHelper::getMyself();
		if ($me->exists() && $attachment->protected & KunenaForumMessageAttachment::PROTECTION_PRIVATE)
		{
			$this->privateAccess($attachment, $me);
		}
		else
		{
			$attachment->tryAuthorise();
		}

		$path = JPATH_ROOT . '/' . $attachment->folder . '/thumb/' . $attachment->filename;

		if (!$thumb || !is_file($path))
		{
			$path = JPATH_ROOT . '/' . $attachment->folder . '/' . $attachment->filename;
		}

		if (!is_file($path))
		{
			// Forum is for registered users only.
			throw new RuntimeException(JText::_('COM_KUNENA_NO_ACCESS'), 404);
		}

		if (headers_sent())
		{
			throw new RuntimeException('HTTP headers were already sent. Sending attachment failed.', 500);
		}

		// Close all output buffers, just in case.
		while(@ob_end_clean());

		// Handle 304 Not Modified
		if (isset($_SERVER['HTTP_IF_NONE_MATCH']))
		{
			$etag = stripslashes($_SERVER['HTTP_IF_NONE_MATCH']);

			if ($etag == $attachment->hash)
			{
				header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT', true, 304);

				// Give fast response.
				flush();
				$this->app->close();
			}
		}

		// Safety check, just in case..
		if (!$attachment->filename_real)
		{
			$attachment->filename_real = $attachment->filename;
		}

		// Set file headers.
		header('ETag: ' . $attachment->hash);
		header('Pragma: public');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');

		if (!$download && $attachment->isImage($attachment->filetype))
		{
			// By default display images inline.
			$maxage = 60 * 60;
			header('Cache-Control: maxage=' . $maxage);
			header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxage) . ' GMT');
			header('Content-type: ' . $attachment->filetype);
			header('Content-Disposition: inline; filename="' . $attachment->filename_real . '"');
		}
		else
		{
			// Otherwise force file download.
			header('Expires: 0');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Content-Description: File Transfer');
			header('Content-Type: application/force-download');
			header('Content-Type: application/octet-stream');
			header('Content-Type: application/download');
			header('Content-Disposition: attachment; filename="' . $attachment->filename_real . '"');
		}

		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . $attachment->size);
		flush();

		// Output the file contents.
		@readfile($path);
		flush();
		$this->app->close();
	}

	protected function privateAccess(KunenaForumMessageAttachment $attachment, KunenaUser $me)
	{
		// Need to load private message (for now allow only one private message per attachment).
		$map = JTable::getInstance('KunenaPrivateAttachmentMap', 'Table');
		$map->load(array('attachment_id' => $attachment->id));

		$finder = new KunenaPrivateMessageFinder();
		$private = $finder->where('id', '=', $map->private_id)->firstOrNew();

		if (!$private->exists())
		{
			throw new RuntimeException(JText::_('COM_KUNENA_NO_ACCESS'), 404);
		}

		if (in_array($me->userid, $private->users()->getMapped()))
		{
			// Yes, I have access..
			return;
		}
		else
		{
			$messages = KunenaForumMessageHelper::getMessages($private->posts()->getMapped());

			foreach ($messages as $message)
			{
				if ($me->isModerator($message->catid))
				{
					// Yes, I have access..
					return;
				}
			}
		}

		throw new RuntimeException(JText::_('COM_KUNENA_NO_ACCESS'), $me->exists() ? 403 : 401);
	}

	/**
	 * Prepare attachment display.
	 *
	 * @return void
	 */
	protected function before()
	{
		// Load language files.
		KunenaFactory::loadLanguage('com_kunena.sys', 'admin');

		$this->me = KunenaUserHelper::getMyself();
		$this->config = KunenaConfig::getInstance();
		$this->document = JFactory::getDocument();
	}
}
