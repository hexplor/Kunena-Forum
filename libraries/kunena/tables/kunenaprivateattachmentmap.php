<?php
/**
 * Kunena Component
 * @package Kunena.Framework
 * @subpackage Tables
 *
 * @copyright (C) 2008 - 2013 Kunena Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.org
 **/
defined ( '_JEXEC' ) or die ();

require_once(__DIR__ . '/kunena.php');

/**
 * Kunena Private Message map to attachments.
 * Provides access to the #__kunena_private_attachment_map table
 */
class TableKunenaPrivateAttachmentMap extends KunenaTable
{
	protected $_autoincrement = false;

	public $private_id = null;
	public $attachment_id = null;

	public function __construct($db)
	{
		parent::__construct('#__kunena_private_attachment_map', array('private_id', 'attachment_id'), $db);
	}
}