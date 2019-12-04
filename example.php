<?php
// Configure your MySQL database connection and particulars of the table you wish to view
$MySQL = array(
  'host' => 'localhost',      // Optional: database server
  'port' => '3306',           // Optional: server port
  'username' => 'user',       // Required: schema username
  'password' => '',           // Required: schema password
  'schema' => 'my_database',  // Required: schema name
  'table' => 'my_table',      // Required: table to view
  'pk' => 'id',               // Required: primary key of table
  'list_cols' => '*',         // Optional: table columns visible in record list
  'list_order' => 'ASC_DESC', // Optional: primary key sort order of record list
  'list_limit' => 100,        // Optional: max records to retrieve for list; 0 = all
  'auth' => false             // Optional: pseudo-password used for URL authentication
);                            //           example.php?auth=yadayada; or false to disable

// Include the table viewer utility
include_once('incl_mysql_table_viewer.php');
?>
