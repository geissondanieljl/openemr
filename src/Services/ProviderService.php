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
}
