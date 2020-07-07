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
                    // Update, if is modified
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
                    $objMember->firstname = ltrim(rtrim($row['firstname']));
                    $objMember->lastname = ltrim(rtrim($row['lastname']));
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
            $objUnique = Database::getInstance()->execute('SELECT * FROM tl_office365_member ORDER BY email');
            while ($objUnique->next())
            {
                if ($objUnique->email != '')
                {
                    if ($this->isUniqueValue('email', $objUnique) === false)
                    {
                        $this->sessionMessage->addErrorMessage(
                            sprintf(
                                'Email address "%s" is not unique!',
                                $objUnique->email
                            )
                        );
                    }
                }

                if ($objUnique->studentId != '0')
                {
                    if ($this->isUniqueValue('studentId', $objUnique) === false)
                    {
                        $this->sessionMessage->addErrorMessage(
                            sprintf(
                                'studentId "%s" for "%s %s" is not unique!',
                                $objUnique->studentId,
                                $objUnique->firstname,
                                $objUnique->lastname
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
     * @return mixed|string
     */
    private function sanitizeName(string $strName)
    {
        $strName = strtolower($strName);
        $strName = str_replace(' ', '', $strName);
        $strName = str_replace('ö', 'oe', $strName);
        $strName = str_replace('ä', 'ae', $strName);
        $strName = str_replace('ü', 'ue', $strName);
        return $strName;
    }

    /**
     * @param string $strField
     * @param $objDb
     * @param string $strTable
     * @return bool
     */
    private function isUniqueValue(string $strField, $objDb, string $strTable = 'tl_office365_member'): bool
    {
        return Database::getInstance()->isUniqueValue($strTable, $strField, $objDb->{$strField}, $objDb->id);
    }

}
