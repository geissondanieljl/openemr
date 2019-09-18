<?php

/**
 * ProviderService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


namespace OpenEMR\Services;

class ProviderService
{

    /**
     * Default constructor.
     */
    public function __construct()
    { }

    public function getAll($data)
    {
        $sqlBindArray = array();
        $sql = "SELECT id,
                       fname,
                       lname,
                       mname,
                       username,
                       npi
                FROM  users
                WHERE authorized = 1 AND active = 1";
        if (!empty($data['npi'])) {
            $sql .= " AND npi = ?";
            array_push($sqlBindArray, $data['npi']);
        }
        $statementResults = sqlStatement($sql, $sqlBindArray);

        $results = array();
        while ($row = sqlFetchArray($statementResults)) {
            array_push($results, $row);
        }

        return $results;
    }

    public function getById($id)
    {
        $sql = "SELECT id,
                       fname,
                       lname,
                       mname,
                       username
                FROM  users
                WHERE authorized = 1 AND active = 1 AND id = ?";

        return sqlQuery($sql, $id);
    }

    public function getAppointments($data)
    {
        $id = $data['providerid'];
        $endTime = date("H:i:s", strtotime($data['endtime']));
        $startTime = date("H:i:s", strtotime($data['starttime']));
        $eventDate = date('Y-m-d', strtotime($data['starttime']));
        $sql = "SELECT
                    ope.pc_eid,
                    ope.pc_catid,
                    ope.pc_aid,
                    ope.pc_pid,
                    ope.pc_eventDate,
                    ope.pc_startTime,
                    ope.pc_endTime,
                    ope.pc_facility,
                    pd.fname,
                    pd.lname
                FROM openemr_postcalendar_events ope
                    JOIN patient_data pd 
                        ON ope.pc_pid = pd.pid 
                WHERE ope.pc_aid = ?
                    AND ope.pc_eventDate = ?
                    AND ope.pc_startTime >= ? 
                    AND ope.pc_endTime <= ?
                    AND ope.pc_apptstatus = 'AVM' ";

        $statementResults = sqlStatement($sql, array($id, $eventDate, $startTime, $endTime));
        $results = array();
        while ($row = sqlFetchArray($statementResults)) {
            array_push($results, $row);
        }

        return array('appointments' => $results);
    }
}
