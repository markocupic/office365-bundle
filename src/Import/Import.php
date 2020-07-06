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

class Import
{

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

        $objCsv = Reader::createFromPath($rootDir . '/' . $objFile->path, 'r');
        $objCsv->setDelimiter($strDelimiter);
        $objCsv->setHeaderOffset(0);


        if($accountType !== 'student')
        {
            $this->sessionMessage->addErrorMessage(sprintf('Account Type "%s" is still not supported.', $accountType));
        }

        if ($accountType === 'student')
        {
            if ($blnTestMode === false)
            {
                $set = ['disable' => '1'];
                Database::getInstance()->prepare('UPDATE tl_office365_member %s WHERE accountType=?')->set($set)->execute($accountType);
            }

            $results = $objCsv->getRecords();

            foreach ($results as $row)
            {
                if ($row['accountType'] !== 'student')
                {
                    continue;
                }
                $intCountRows++;

                $objMember = Office365MemberModel::findOneByStudentId($row['studentId']);
                if ($objMember !== null)
                {
                    // Update, if is modified
                    $objMember->teacherAcronym = $row['teacherAcronym'];
                    $objMember->firstname = $row['firstname'];
                    $objMember->lastname = $row['lastname'];
                    $objMember->accountType = $row['accountType'];
                    $objMember->name = $objMember->firstname . ' ' . $objMember->lastname;
                    // Do not overwrite email or initialPassword!!!!!

                    if ($objMember->isModified())
                    {
                        $intCountUpdates++;
                        $objMember->tstamp = time();
                        $this->sessionMessage->addInfoMessage(sprintf('Updated student "%s %s [%s]"', $row['firstname'], $row['lastname'], $row['email']));
                    }

                    if ($blnTestMode === false)
                    {
                        $objMember->disable = '';
                        $objMember->save();
                    }
                }
                else
                {
                    // Insert
                    $intCountInserts++;

                    $objMember = new Office365MemberModel();
                    $objMember->accountType = $row['accountType'];
                    $objMember->studentId = $row['studentId'];
                    $objMember->teacherAcronym = $row['teacherAcronym'];
                    $objMember->firstname = $row['firstname'];
                    $objMember->lastname = $row['lastname'];
                    $objMember->name = $objMember->firstname . ' ' . $objMember->lastname;
                    $objMember->email = $row['email'];
                    $objMember->dateAdded = time();
                    $objMember->tstamp = time();
                    if ($blnTestMode === false)
                    {
                        $objMember->save();
                    }

                    $this->sessionMessage->addInfoMessage(sprintf('Added new student "%s %s [%s]"', $row['firstname'], $row['lastname'], $row['email']));
                }
            }

            // Alert deactivated students
            $objDisabledStudents = Database::getInstance()->prepare('SELECT * FROM tl_office365_member WHERE accountType=? AND disable=?')->execute($accountType, '1');
            while ($objDisabledStudents->next())
            {
                $this->sessionMessage->addInfoMessage(
                    sprintf(
                        'Deactivated student "%s %s"',
                        $objDisabledStudents->firstname,
                        $objDisabledStudents->lastname
                    )
                );
            }

            // Add summary
            $this->sessionMessage->addInfoMessage(
                sprintf(
                    'Finished import process. Traversed %s datarecords. %s inserts and %s updates. Deactivated students: %s',
                    $intCountRows,
                    $intCountInserts,
                    $intCountUpdates,
                    $objDisabledStudents->numRows
                )
            );
        }
    }

}
