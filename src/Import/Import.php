<?php

/**
 * @copyright  Marko Cupic 2020 <m.cupic@gmx.ch>
 * @author     Marko Cupic
 * @package    Office365Bundle for Schule Ettiswil
 * @license    MIT
 * @see        https://github.com/markocupic/office365-bundle
 *
 */

declare(strict_types=1);

namespace Markocupic\Office365Bundle\Import;

use Contao\File;
use Contao\Message;
use Contao\System;
use Contao\Database;
use Contao\Validator;
use League\Csv\Reader;
use Markocupic\Office365Bundle\Message\SessionMessage;
use Markocupic\Office365Bundle\Model\Office365MemberModel;

/**
 * Class Import
 * @package Markocupic\Office365Bundle\Import
 */
class Import
{

    /** @var SessionMessage */
    private $sessionMessage;

    /**
     * Import constructor.
     * @param SessionMessage $sessionMessage
     */
    public function __construct(SessionMessage $sessionMessage)
    {
        $this->sessionMessage = $sessionMessage;
    }

    /**
     * @param string $accountType
     * @param File $objFile
     * @param string $strDelimiter
     * @param bool $blnTestMode
     * @throws \League\Csv\Exception
     */
    public function initImport(string $accountType, File $objFile, string $strDelimiter = ';', bool $blnTestMode = false): void
    {
        $rootDir = System::getContainer()->getParameter('kernel.project_dir');

        $intCountInserts = 0;
        $intCountUpdates = 0;
        $intCountRows = 0;
        $activeStudentIDS = [];

        $objCsv = Reader::createFromPath($rootDir . '/' . $objFile->path, 'r');
        $objCsv->setDelimiter($strDelimiter);
        $objCsv->setHeaderOffset(0);

        if ($blnTestMode === true)
        {
            $this->sessionMessage->addInfoMessage('Run script in test mode.');
        }

        if ($accountType !== 'student')
        {
            $this->sessionMessage->addErrorMessage(sprintf('Account Type "%s" is still not supported.', $accountType));
        }

        if ($accountType === 'student')
        {
            $results = $objCsv->getRecords();

            foreach ($results as $row)
            {
                // Remove whitespaces
                $row = array_map('trim', $row);

                if ($row['accountType'] !== 'student')
                {
                    continue;
                }

                if (!is_numeric($row['studentId']))
                {
                    $this->sessionMessage->addErrorMessage(sprintf('Invalid student id found for "%s %s [%s]"!', $row['firstname'], $row['lastname'], $row['email']));
                    continue;
                }

                $intCountRows++;

                $objMember = Office365MemberModel::findOneByStudentId($row['studentId']);
                if ($objMember !== null)
                {
                    $activeStudentIDS[] = $row['studentId'];
                    // Update, if modified
                    $arrFields = ['teacherAcronym', 'firstname', 'lastname', 'ahv', 'notice'];
                    foreach ($arrFields as $field)
                    {
                        if (!empty($row[$field]))
                        {
                            $objMember->$field = $row[$field];
                        }
                    }

                    $objMember->name = $objMember->firstname . ' ' . $objMember->lastname;
                    if ($objMember->initialPassword == '' && !empty($row['initialPassword']))
                    {
                        $objMember->initialPassword = $row['initialPassword'];
                    }
                    // Do not overwrite email or initialPassword!!!!!

                    if ($objMember->isModified())
                    {
                        $intCountUpdates++;
                        $objMember->tstamp = time();
                        $this->sessionMessage->addInfoMessage(sprintf('Update student "%s %s [%s]"', $row['firstname'], $row['lastname'], $row['email']));
                    }

                    if ($blnTestMode === false)
                    {
                        $objMember->save();
                    }
                }
                else
                {
                    // Insert
                    $intCountInserts++;

                    $objMember = new Office365MemberModel();
                    $activeStudentIDS[] = $row['studentId'];
                    $objMember->accountType = $row['accountType'];
                    $objMember->studentId = $row['studentId'];
                    $objMember->teacherAcronym = $row['teacherAcronym'];
                    $objMember->firstname = $row['firstname'];
                    $objMember->lastname = $row['lastname'];
                    $objMember->name = $objMember->firstname . ' ' . $objMember->lastname;
                    $objMember->email = $row['email'];
                    $objMember->ahv = $row['ahv'];
                    $objMember->notice = $row['notice'];

                    $objMember->dateAdded = time();
                    $objMember->tstamp = time();

                    if ($row['email'] == '')
                    {
                        $fn = $this->sanitizeName($row['firstname']);
                        $ln = $this->sanitizeName($row['lastname']);
                        $row['email'] = sprintf('%s_%s@stud.schule-ettiswil.ch', $fn, $ln);
                        $objMember->email = $row['email'];
                    }
                    if ($blnTestMode === false)
                    {
                        $objMember->save();
                    }

                    $this->sessionMessage->addInfoMessage(sprintf('Add new student "%s %s [%s]. Check data(f.ex. email address)!!!!"', $row['firstname'], $row['lastname'], $row['email']));
                }
            }

            // Alert, count and disable no more active students
            $intCountDeactivatedStudents = 0;
            if (!empty($activeStudentIDS))
            {
                $objDisabledStudents = Database::getInstance()->prepare('SELECT * FROM tl_office365_member WHERE accountType=? AND tl_office365_member.studentId NOT IN(' . implode(',', $activeStudentIDS) . ')')->execute($accountType);
                $intCountDeactivatedStudents = $objDisabledStudents->numRows;

                while ($objDisabledStudents->next())
                {
                    if ($blnTestMode === false)
                    {
                        // Disable deactivated student
                        Database::getInstance()->prepare('UPDATE tl_office365_member SET disable="1" WHERE id=?')->execute($objDisabledStudents->id);
                    }

                    $this->sessionMessage->addInfoMessage(
                        sprintf(
                            'Deactivate student "%s %s"',
                            $objDisabledStudents->firstname,
                            $objDisabledStudents->lastname
                        )
                    );
                }
            }

            // Check for uniqueness
            $objUser = Database::getInstance()->execute('SELECT * FROM tl_office365_member ORDER BY email');
            while ($objUser->next())
            {
                if ($objUser->email != '')
                {
                    if (!Database::getInstance()->isUniqueValue('tl_office365_member', 'email', $objUser->email, $objUser->id))
                    {
                        $this->sessionMessage->addErrorMessage(
                            sprintf(
                                'Email address "%s" is not unique!',
                                $objUser->email
                            )
                        );
                    }

                    if (!Validator::isEmail($objUser->email))
                    {
                        $this->sessionMessage->addErrorMessage(
                            sprintf(
                                'Invalid email address "%s"!',
                                $objUser->email
                            )
                        );
                    }
                }

                if ($objUser->studentId != '0')
                {
                    if (!Database::getInstance()->isUniqueValue('tl_office365_member', 'studentId', $objUser->studentId, $objUser->id))
                    {
                        $this->sessionMessage->addErrorMessage(
                            sprintf(
                                'studentId "%s" for "%s %s" is not unique!',
                                $objUser->studentId,
                                $objUser->firstname,
                                $objUser->lastname
                            )
                        );
                    }
                }
            }

            // Add summary
            $this->sessionMessage->addInfoMessage(
                sprintf(
                    'Terminated import process. Traversed %s datarecords. %s inserts and %s updates. Deactivated students: %s',
                    $intCountRows,
                    $intCountInserts,
                    $intCountUpdates,
                    $intCountDeactivatedStudents
                )
            );
        }
    }

    /**
     * @param string $strName
     * @return string
     */
    private function sanitizeName(string $strName = '')
    {
        $strName = trim($strName);

        $strName = preg_replace('/[\pC]/u', '', $strName);

        if ($strName === null)
        {
            throw new \InvalidArgumentException('The file name could not be sanitized');
        }

        $arrRep = [
            'Ö'  => 'OE',
            'Ä'  => 'AE',
            'Ü'  => 'UE',
            'É'  => 'E',
            'À'  => 'A',
            'ö'  => 'oe',
            'ä'  => 'oe',
            'ü'  => 'oe',
            'é'  => 'oe',
            'à'  => 'oe',
            'ç'  => 'c',
            '´`' => '',
            '`'  => '',
        ];

        foreach ($arrRep as $k => $v)
        {
            $strName = str_replace($k, $v, $strName);
        }

        $strName = strtolower($strName);

        return trim($strName);
    }

}
