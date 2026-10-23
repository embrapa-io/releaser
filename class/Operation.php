<?php

class Operation
{
    private $info = '';
    private $method = '';
    private $params = 0;
    private $usage = '';
    private $examples = [];
    private $group = '';  // grupo no menu (chave de Menu::GROUPS)
    private $detail = ''; // descrição completa, só no --help ($info é a curta, do menu)

    public function __construct ($info, $method, $params, $usage, $examples, $group = '', $detail = '')
    {
        $this->info = $info;
        $this->method = $method;
        $this->params = intval ($params);
        $this->usage = $usage;
        $this->examples = is_array ($examples) ? $examples : [ $examples ];
        $this->group = $group;
        $this->detail = $detail;
    }

    public function __get ($property)
    {
        if (property_exists ($this, $property)) return $this->$property;
    }
}