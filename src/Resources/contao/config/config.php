<?php

/**
 * @copyright  Marko Cupic 2020 <m.cupic@gmx.ch>
 * @author     Marko Cupic
 * @package    Office365Bundle for Schule Ettiswil
 * @license    MIT
 * @see        https://github.com/markocupic/office365-bundle
 *
 */

/**
 * Back end modules
 */
$GLOBALS['BE_MOD']['office365'] = array(
    'office365_member'     => array
    (
        'tables' => array('tl_office365_member'),
    ),
);
