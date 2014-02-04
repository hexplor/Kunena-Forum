<?php
/**
 * Kunena Component
 * @package Kunena.Framework
 * @subpackage Forum.Message.Attachment
 *
 * @copyright (C) 2008 - 2013 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

/**
 * Class KunenaAttachment
 *
 * @property int $id
 * @property int $userid
 * @property int $mesid
 * @property int $protected
 * @property string $hash
 * @property int $size
 * @property string $folder
 * @property string $filetype
 * @property string $filename
 * @property string $filename_real
 * @property string $caption
 *
 * @property int    $width   Image width (0 for non-images).
 * @property int    $height  Image height (0 for non-images).
 *
 * @since 3.1
 */
class KunenaAttachment extends KunenaDatabaseObject
{
	// Higher protection level means that the attachment is visible to less people.
	// Protection level can be checked as bitmask: PROTECTION_ACL + PROTECTION_FRIENDS.
	// To filter out attachments when doing a database query, you can use:
	// Visible for author = value < PROTECTION_AUTHOR * 2
	// TODO: Implement these
	const PROTECTION_NONE = 0;
	const PROTECTION_PUBLIC = 1;
	const PROTECTION_ACL = 2;
	const PROTECTION_FRIENDS = 4;
	const PROTECTION_MODERATORS = 8;
	const PROTECTION_ADMINS = 16;
	const PROTECTION_PRIVATE = 32;
	const PROTECTION_AUTHOR = 64;
	const PROTECTION_UNPUBLISHED = 128;

	/**
	 * @var int
	 */
	public $id = null;

	protected $_table = 'KunenaAttachments';

	protected $path;
	protected $width;
	protected $height;
	protected $shortname;

	/**
	 * @var bool
	 */
	public $disabled = false;

	protected static $_directory = 'media/kunena/attachments';
	protected static $actions  = array(
		'read'=>array('Read'),
		'create'=>array(),
		'delete'=>array('Exists', 'Own'),
	);

	/**
	 * @param mixed $identifier
	 * @param bool $reload
	 *
	 * @return KunenaAttachment
	 *
	 * @since 3.1
	 */
	public static function getInstance($identifier = null, $reload = false)
	{
		return KunenaAttachmentHelper::get($identifier, $reload);
	}

	/**
	 * Destructor deletes the files from the filesystem if attachment isn't stored in database.
	 *
	 * @since 3.1
	 */
	public function __destruct()
	{
		if (!$this->exists())
		{
			$this->deleteFile();
		}
	}

	/**
	 * Getter function.
	 *
	 * @param  string  $property
	 *
	 * @return string
	 * @throws InvalidArgumentException
	 *
	 * @since 3.1
	 */
	public function __get($property)
	{
		if ($this->width == null) $this->initialize();
		switch ($property) {
			case 'width':
				return $this->width;
			case 'height':
				return $this->height;
		}

		throw new InvalidArgumentException(sprintf('Property "%s" is not defined', $property));
	}

	/**
	 * Check if attachment is image.
	 *
	 * @return  bool  True if attachment is image.
	 *
	 * @since 3.1
	 */
	public function isImage()
	{
		return (stripos($this->filetype, 'image/') !== false);
	}


	/**
	 * Get path for the file.
	 *
	 * @param bool $thumb
	 *
	 * @return string|false  Path to the file or false if file doesn't exist.
	 *
	 * @since 3.1
	 */
	public function getPath($thumb = false)
	{
		if ($thumb)
		{
			$path = JPATH_ROOT . "/{$this->folder}/thumb/{$this->filename}";
			$path = is_file($path) ? $path : false;
		}
		else
		{
			$path = JPATH_ROOT . "/{$this->folder}/{$this->filename}";
			$path = is_file($path) ? $path : false;
		}

		return $path;
	}

	/**
	 * Get filename for output.
	 *
	 * @param bool $escape
	 *
	 * @return string
	 *
	 * @since 3.1
	 */
	public function getFilename($escape = true)
	{
		$filename = $this->protected ? $this->filename_real : $this->filename;

		return $escape ? htmlspecialchars($filename, ENT_COMPAT, 'UTF-8') : $filename;
	}

	/**
	 * Get extension of file for output.
	 *
	 * @param bool $escape
	 *
	 * @return string
	 *
	 * @since 3.1
	 */
	public function getExtension($escape = true)
	{
		$filename  = $this->protected ? $this->filename_real : $this->filename;
		$extension = pathinfo($filename, PATHINFO_EXTENSION);

		return $escape ? htmlspecialchars($extension, ENT_COMPAT, 'UTF-8') : $extension;
	}

	/**
	 * This function shortens long filenames for display purposes.
	 *
	 * The first 8 characters of the filename, followed by three dots and the last 5 character of the filename.
	 *
	 * @param int    $front
	 * @param int    $back
	 * @param string $filler
	 * @param bool   $escape
	 *
	 * @return string
	 *
	 * @since 3.1
	 */
	public function getShortName($front = 10, $back = 8, $filler = '...', $escape = true)
	{
		if ($this->shortname === null)
		{
			$this->shortname = KunenaAttachmentHelper::shortenFileName($this->getFilename(false), $front, $back, $filler);
		}

		return $escape ? htmlspecialchars($this->shortname, ENT_COMPAT, 'UTF-8') : $this->shortname;
	}

	/**
	 * Get URL pointing to the attachment.
	 *
	 * @param bool $thumb
	 * @param bool $inline
	 * @param bool $escape
	 *
	 * @return string
	 *
	 * @since 3.1
	 */
	public function getUrl($thumb = false, $inline = true, $escape = true)
	{
		// Generic thumbnails are special case.
		if ($thumb && !$this->isImage())
		{
			return JUri::root(true) .'/media/kunena/images/attach_generic.png';
		}

		$protect = (bool) KunenaConfig::getInstance()->attachment_protection;

		// Use direct URLs to the attachments if protection is turned off and file wasn't protected.
		if (!$protect && !$this->protected)
		{
			$file = $this->folder . '/' . $this->filename;
			$fileThumb = $this->folder . '/thumb/' . $this->filename;

			if (!is_file(JPATH_ROOT . '/' . $fileThumb))
			{
				$fileThumb = $file;
			}

			$url = JUri::root(true) .'/'. ($thumb ? $fileThumb : $file);
			return $escape ? htmlspecialchars($url, ENT_COMPAT, 'UTF-8') : $url;
		}

		// Route attachment through Kunena.
		$thumb = $thumb ? '&thumb=1' : '';
		$download = $inline ? '' : '&download=1';
		$filename = urlencode($this->getFilename(false));

		return KunenaRoute::_("index.php?option=com_kunena&view=attachment&id={$this->id}{$thumb}{$download}&filename={$filename}&format=raw", $escape);
	}

	/**
	 * Get attachment layout.
	 *
	 * @return KunenaLayout
	 */
	public function getLayout()
	{
		return KunenaLayout::factory('Attachment/Item')->set('attachment', $this);
	}

	/**
	 * @return string
	 * @deprecated 3.1
	 */
	public function getTextLink()
	{
		return (string) KunenaLayout::factory('Attachment/Item')->set('attachment', $this)->setLayout('textlink');
	}

	/**
	 * @return string
	 * @deprecated 3.1
	 */
	public function getImageLink()
	{
		return $this->isImage()
			? (string) KunenaLayout::factory('Attachment/Item')->set('attachment', $this)->setLayout('image') : null;
	}

	/**
	 * @return string
	 * @deprecated 3.1
	 */
	public function getThumbnailLink()
	{
		return (string) KunenaLayout::factory('Attachment/Item')->set('attachment', $this)->setLayout('thumbnail');
	}

	/**
	 * Get message to which attachment has been attached into.
	 *
	 * NOTE: Returns message object even if there isn't one. Please call $message->exists() to check if it exists.
	 *
	 * @return KunenaForumMessage
	 *
	 * @since 3.1
	 */
	public function getMessage()
	{
		return KunenaForumMessageHelper::get($this->mesid);
	}

	/**
	 * Get author of the attachment.
	 *
	 * @return KunenaUser
	 *
	 * @since 3.1
	 */
	public function getAuthor()
	{
		return KunenauserHelper::get($this->userid);
	}

	/**
	 * Returns true if user is authorised to do the action.
	 *
	 * @param string     $action
	 * @param KunenaUser $user
	 *
	 * @return bool
	 *
	 * @since 3.1
	 */
	public function isAuthorised($action = 'read', KunenaUser $user = null)
	{
		return !$this->tryAuthorise($action, $user, false);
	}

	/**
	 * Throws an exception if user isn't authorised to do the action.
	 *
	 * @param string      $action
	 * @param KunenaUser  $user
	 * @param bool        $throw
	 *
	 * @return KunenaExceptionAuthorise|null
	 * @throws KunenaExceptionAuthorise
	 * @throws InvalidArgumentException
	 *
	 * @since 3.1
	 */
	public function tryAuthorise($action = 'read', KunenaUser $user = null, $throw = true)
	{
		// Special case to ignore authorisation.
		if ($action == 'none')
		{
			return null;
		}

		// Load user if not given.
		if ($user === null)
		{
			$user = KunenaUserHelper::getMyself();
		}

		// Unknown action - throw invalid argument exception.
		if (!isset(self::$actions[$action]))
		{
			throw new InvalidArgumentException(JText::sprintf('COM_KUNENA_LIB_AUTHORISE_INVALID_ACTION', $action), 500);
		}

		// Start by checking if attachment is protected.
		$exception = !$this->protected
			? null : new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), $user->id ? 403 : 401);

		// TODO: Add support for PROTECTION_PUBLIC

		// Currently we only support ACL checks, not public attachments.
		if ($exception && $this->mesid && $this->protected & (self::PROTECTION_PUBLIC + self::PROTECTION_ACL))
		{
			// Load message authorisation.
			$exception = $this->getMessage()->tryAuthorise('attachment.'.$action, $user, false);
		}

		// TODO: Add support for PROTECTION_FRIENDS
		// TODO: Add support for PROTECTION_MODERATORS
		// TODO: Add support for PROTECTION_ADMINS

		// Check if attachment is private.
		if ($exception && $this->protected & self::PROTECTION_PRIVATE)
		{
			$exception = $this->authorisePrivate($action, $user);
		}

		// Check author access.
		if ($exception && $this->protected & self::PROTECTION_AUTHOR)
		{
			$exception = $user->exists() && $user->id == $this->userid
				? null : new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), $user->userid ? 403 : 401);
		}

		if ($exception) {
			// Hide original exception behind no access.
			$exception = new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), $user->userid ? 403 : 401, $exception);
		} else {
			// Check authorisation action.
			foreach (self::$actions[$action] as $function)
			{
				$authFunction = 'authorise'.$function;
				$exception = $this->$authFunction($user);
				if ($exception) break;
			}
		}

		// Throw or return the exception.
		if ($throw && $exception)
		{
			throw $exception;
		}

		return $exception;
	}

	/**
	 * @param string $action
	 * @param mixed $user
	 * @param bool $silent
	 *
	 * @return bool
	 * @deprecated 3.1
	 */
	public function authorise($action='read', $user=null, $silent=false)
	{
		if ($user === null)
		{
			$user = KunenaUserHelper::getMyself();
		}
		elseif (!($user instanceof KunenaUser))
		{
			$user = KunenaUserHelper::get($user);
		}

		$exception = $this->tryAuthorise($action, $user, false);
		if ($silent === false && $exception) $this->setError($exception->getMessage());

		if ($silent !== null) return !$exception;

		return $exception ? $exception->getMessage() : null;
	}

	/**
	 * @param string $key
	 * @param null|int   $catid
	 *
	 * @return bool
	 *
	 * @since 3.1
	 */
	function upload($key = 'kattachment', $catid = null)
	{
		// TODO: get rid of the legacy upload class.
		require_once KPATH_SITE . '/lib/kunena.upload.class.php';

		$path = JPATH_ROOT . '/media/kunena/attachments/' . $this->userid;
		$upload = new CKunenaUpload($catid);
		$protection = (bool) KunenaConfig::getInstance()->attachment_protection;
		$filename = $protection ? null : '';
		$upload->uploadFile($path, $key, $filename, false);
		$fileinfo = $upload->getFileInfo();

		if ($fileinfo ['status'] && $fileinfo['ready'] === true)
		{
			$this->protected = (int) $protection;
			$this->hash =$fileinfo ['hash'];
			$this->size = $fileinfo ['size'];
			$this->folder = 'media/kunena/attachments/' . $this->userid;
			$this->filetype = $fileinfo ['mime'];
			$this->filename = $fileinfo ['name'];
			$this->filename_real = $fileinfo ['real'];
			$this->caption = '';

			return true;
		}

		$this->setError(JText::sprintf('COM_KUNENA_UPLOAD_FAILED', $fileinfo['name']) . ': ' . $fileinfo['error']);

		return false;
	}

	/**
	 * Set attachment file.
	 *
	 * Copies the attachment into proper location and makes sure that all the unset fields get properly assigned.
	 *
	 * @param  string  $source     Absolute path to the upcoming attachment.
	 * @param  string  $basename   Filename without extension.
	 * @param  string  $extension  File extension.
	 * @param  bool    $unlink     Whether to delete the original file or not.
	 * @param  bool    $overwrite  If not allowed, throw exception if the file exists.
	 *
	 * @return bool
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 *
	 * @since 3.1
	 */
	public function saveFile($source, $basename = null, $extension = null, $unlink = false, $overwrite = false)
	{
		if (!is_file($source))
		{
			throw new InvalidArgumentException(__CLASS__.'::'.__METHOD__.'(): Attachment file not found.');
		}

		// Hash, size and MIME are set during saving, so let's deal with all other variables.
		$this->userid = is_null($this->userid) ? KunenaUserHelper::getMyself() : $this->userid;
		$this->folder = is_null($this->folder) ? "media/kunena/attachments/{$this->userid}" : $this->folder;
		$this->protected = is_null($this->protected) ? (bool) KunenaConfig::getInstance()->attachment_protection : $this->protected;

		if (!$this->filename_real)
		{
			$this->filename_real = $this->filename;
		}

		if (!$this->filename || $this->filename == $this->filename_real)
		{
			if (!$basename || !$extension)
			{
				throw new InvalidArgumentException(__CLASS__.'::'.__METHOD__.'(): Parameters $basename or $extension not provided.');
			}

			// Find available filename.
			$this->filename = KunenaAttachmentHelper::getAvailableFilename(
				$this->folder, $basename, $extension, $this->protected
			);
		}

		// Create target directory if it does not exist.
		if (!KunenaFolder::exists(JPATH_ROOT . "/{$this->folder}") && !KunenaFolder::create(JPATH_ROOT . "/{$this->folder}"))
		{
			throw new RuntimeException(JText::_('Failed to create attachment directory.'));
		}

		$destination = JPATH_ROOT . "/{$this->folder}/{$this->filename}";

		// Move the file into the final location (if not already in there).
		if ($source != $destination)
		{
			// Create target directory if it does not exist.
			if (!$overwrite && is_file($destination))
			{
				throw new RuntimeException(JText::sprintf('Attachment %s already exists.'), $this->filename_real);
			}

			if ($unlink)
			{
				@chmod($source, 0644);
			}

			$success = KunenaFile::copy($source, $destination);

			if (!$success)
			{
				throw new RuntimeException(JText::sprintf('COM_KUNENA_UPLOAD_ERROR_NOT_MOVED', $destination));
			}

			KunenaPath::setPermissions($destination);

			if ($unlink)
			{
				unlink($source);
			}
		}

		return $this->save();
	}

	// Internal functions

	/**
	 * @internal
	 * @since 3.1
	 */
	protected function initialize()
	{
		$path = $this->getPath();

		if ($path && $this->isImage())
		{
			list($this->width, $this->height) = getimagesize($path);
		}
		else
		{
			$this->width = $this->height = 0;
		}
	}

	/**
	 * @internal
	 * @since 3.1
	 */
	protected function deleteFile()
	{
		if (self::$_directory != substr($this->folder, 0, strlen(self::$_directory)))
		{
			return;
		}

		$path = JPATH_ROOT . "/{$this->folder}";
		$filename = $path.'/'.$this->filename;

		if (is_file($filename))
		{
			KunenaFile::delete($filename);
		}

		$filename = $path.'/raw/'.$this->filename;

		if (is_file($filename))
		{
			KunenaFile::delete($filename);
		}

		$filename = $path.'/thumb/'.$this->filename;

		if (is_file($filename))
		{
			KunenaFile::delete($filename);
		}
	}

	protected function authorisePrivate($action, KunenaUser $user)
	{
		if (!$user->exists())
		{
			return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), 401);
		}

		if ($action == 'create')
		{
			return null;
		}

		// Need to load private message (for now allow only one private message per attachment).
		$map = JTable::getInstance('KunenaPrivateAttachmentMap', 'Table');
		$map->load(array('attachment_id' => $this->id));

		$finder = new KunenaPrivateMessageFinder();
		$private = $finder->where('id', '=', $map->private_id)->firstOrNew();

		if (!$private->exists())
		{
			return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), 403);
		}

		if (in_array($user->userid, $private->users()->getMapped()))
		{
			// Yes, I have access..
			return null;
		}
		else
		{
			$messages = KunenaForumMessageHelper::getMessages($private->posts()->getMapped());

			foreach ($messages as $message)
			{
				if ($user->isModerator($message->getCategory()))
				{
					// Yes, I have access..
					return null;
				}
			}
		}

		return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), 403);
	}

	/**
	 * @param KunenaUser $user
	 *
	 * @return KunenaExceptionAuthorise|null
	 *
	 * @since 3.1
	 */
	protected function authoriseExists(KunenaUser $user)
	{
		// Checks if attachment exists
		if (!$this->exists())
		{
			return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), 404);
		}

		return null;
	}

	/**
	 * @param KunenaUser $user
	 *
	 * @return KunenaExceptionAuthorise|null
	 *
	 * @since 3.1
	 */
	protected function authoriseRead(KunenaUser $user)
	{
		// Checks if attachment exists
		if (!$this->exists())
		{
			return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), 404);
		}

		if (!$user->exists())
		{
			$config = KunenaConfig::getInstance();

			if ($this->isImage() && !$config->showimgforguest)
			{
				return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_SHOWIMGFORGUEST_HIDEIMG'), 401);
			}

			if (!$this->isImage() && !$config->showfileforguest)
			{
				return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_SHOWIMGFORGUEST_HIDEFILE'), 401);
			}
		}

		return null;
	}

	/**
	 * @param KunenaUser $user
	 *
	 * @return KunenaExceptionAuthorise|null
	 *
	 * @since 3.1
	 */
	protected function authoriseOwn(KunenaUser $user)
	{
		// Checks if attachment is users own or user is moderator in the category (or global)
		if (($user->userid && $this->userid != $user->userid) && !$user->isModerator($this->getMessage()->getCategory()))
		{
			return new KunenaExceptionAuthorise(JText::_('COM_KUNENA_ATTACHMENT_NO_ACCESS'), 403);
		}

		return null;
	}
}
