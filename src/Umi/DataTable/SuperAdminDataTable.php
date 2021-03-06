<?php

namespace YM\Umi\DataTable;

use YM\Umi\umiDataTableBuilder;

class SuperAdminDataTable extends umiDataTableAbstract
{
    private $umiDataTable;

    public function __construct()
    {
        $this->umiDataTable = new umiDataTableBuilder();
    }

    public function headerAlert()
    {
        return $this->umiDataTable->tableHeadAlert(true);
    }

    public function header()
    {
        return $this->headerAlert() .
        $this->umiDataTable->tableHead(true);
    }

    public function tableBody()
    {
        return $this->umiDataTable->tableBody(true);
    }

    public function footer()
    {
        return $this->umiDataTable->tableFoot(true);
    }
}