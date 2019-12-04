<?php
session_start(); // Session variable is used for authentication

// Bail out: This file is to be included, not called directly
if(count(get_included_files()) == 1) {
  die('Direct access is not permitted.');
}

// Default configuration:
// Associative array $MySQL expected to be set in including file.
$MySQL_default = array(
  'host' => 'localhost',      // database server
  'port' => '3306',           // server port
  'username' => 'user',       // schema username
  'password' => '',           // schema password
  'schema' => 'my_database',  // schema name
  'table' => 'my_table',      // table to view
  'pk' => 'id',               // primary key of table
  'list_cols' => '*',         // table columns visible in record list
  'list_order' => 'asc',      // primary key sort order of record list
  'list_limit' => '100',      // max records to retrieve for list; 0 = all
  'auth' => false             // pseudo-password used for URL authentication
);                            //   example.php?auth=yadayada; or false to disable

// Bail out: No configuration
if(!isset($MySQL) || !is_array($MySQL)) {
  die('Utility is not configured.');
}
$MySQL = array_replace($MySQL_default, $MySQL);

// Ensure that primary key is among the fields to be displayed in the record list
if(trim($MySQL['list_cols']) != '*' && !in_array($MySQL['pk'], explode(',', $MySQL['list_cols']))) {
  $MySQL['list_cols'] = $MySQL['pk'] . ',' . $MySQL['list_cols'];
}

// Build SQL SELECT statement to retrieve record list
$MySQL['list_sql'] = 'select ' . $MySQL['list_cols'] 
  . ' from ' . $MySQL['table'] 
  . ' order by ' . $MySQL['pk'] . ' ' . $MySQL['list_order'] 
  . ((is_numeric($MySQL['list_limit']) && $MySQL['list_limit'] > 0) ? ' limit 100' : '');

// Set page title
$page_title = 'MySQL table viewer: ' . $MySQL['table'];

// URL query string parameter: pk (primary key value for individual record retrieval)
$urlqs_pk = (array_key_exists('pk', $_GET) ? $_GET['pk'] : false);

// URL query string parameter: auth (pseudo-password string for session authentication)
$urlqs_auth = (array_key_exists('auth', $_GET) ? $_GET['auth'] : false);

// Generate identity string to uniquely identify an authenticated session
$auth_ident = md5($MySQL['schema'] . $MySQL['table'] . $MySQL['pk']);

// Authorization:
// Default to no authorization
if(!array_key_exists('auth_timeout_' . $auth_ident, $_SESSION)) {
  $_SESSION['auth_timeout_' . $auth_ident] = 0;
}
// Authorization pseudo-password wasn't set; timeout session in one week
if($MySQL['auth'] === false) {
  $_SESSION['auth_timeout_' . $auth_ident] = time() + (60*60*24*7);
}
// Authorization pseudo-password was set, and query string parameter matches;
//   timeout session in one hour
else if($urlqs_auth !== false) {
  if($urlqs_auth == $MySQL['auth']) {
    $_SESSION['auth_timeout_' . $auth_ident] = time() + (60*60);
  }
}
// Bail out: Session timeout is zero; no permission to view page
if($_SESSION['auth_timeout_' . $auth_ident] <= 0) {
  die('No authorization to view.');
}
// Bail out: Session timeout has lapsed; revisit auth URL to resume
if($_SESSION['auth_timeout_' . $auth_ident] < time()) {
  die('Viewing session has timed out.');
}

// Connect to the database
$db = new mysqli(
  $MySQL['host'],
  $MySQL['username'],
  $MySQL['password'],
  $MySQL['schema'],
  $MySQL['port']
  );
if($db->connect_error) {
  die('Failed to connect to database: ' . $MySQL['username'] . '@' . $MySQL['host'] . ':' . $MySQL['port'] . '/' . $MySQL['schema'] . '.');
}

// Function:
//   markupRowList
//   Perform query to select records for list view, generate corresponding HTML table
// Parameters:
//   $db - database connection object
//   $sql - SQL SELECT statement
//   $table - table name (passed straight through to function markupRecord)
//   $col_pk - column name of table primary key
//   $urlqs_pk - URL query string, primary key value of record currently being viewed
// Returns:
//   HTML markup
function markupRowList($db, $sql, $table, $col_pk, $urlqs_pk) {
  $markup = '';
  // Query database
  $q = $db->query($sql);
  $row_count = 0;
  // Fetch and step through records
  while($r = $q->fetch_array(MYSQLI_ASSOC)) {
    // Every 25th record, repeat thead for improved list legibility
    if($row_count % 25 == 0) {
      if(empty($markup)) {
        $markup .= '<table>';
      }
      else {
        $markup .= '</tbody>';
      }
      $markup .= '<thead><tr>';
      foreach($r as $rKey => $rValue) {
        $markup .= '<th>' . $rKey . '</th>';
      }
      $markup .= '<tbody>';
    }
    // Markup list columns; link primary key, indicate active/viewing record
    $markup .= '<tr id="' . $r[$col_pk] . '"' . ($urlqs_pk === $r[$col_pk] ? ' class="active"' : '') . '>';
    foreach($r as $rKey => $rValue) {
      if($rKey == $col_pk) {
        $markup .= '<td class="primary_key"><a href="?pk=' . $r[$col_pk] . '#' . $r[$col_pk] . '" title="View record ' . $r[$col_pk] . '">' . $rValue . '</a></td>';
      }
      else {
        $markup .= '<td>' . $rValue . '</td>';
      }
    }
    $markup .= '</tr>';
    // Viewing a specific record, markup its contents
    if($urlqs_pk === $r[$col_pk]) {
      $markup .= '<tr id="' . $r[$col_pk] . '_record" class="active">';
      $markup .= '<td colspan="' . count($r) . '">';
      $markup .= markupRecord($db, $table, $col_pk, $urlqs_pk);
      $markup .= '</td>';
      $markup .= '</tr>';
    }
    $markup .= "\n";
    $row_count++;
  }
  if(!empty($markup)) {
    $markup .= '</tbody></table>';
    $markup .= '<p><em>(' . $row_count . ' rows)</em></p>';
  }
  // Finish with query object
  $q->close();
  // Return generated HTML markup
  return $markup;
}

// Function:
//   markupRecord
//   Perform query to select an individual record, generate corresponding HTML definition list
//     of column names and values
// Parameters:
//   $db - database connection object
//   $table - table name
//   $col_pk - column name of table primary key
//   $urlqs_pk - URL query string, primary key value of record currently being viewed
// Returns:
//   HTML markup
function markupRecord($db, $table, $col_pk, $urlqs_pk) {
  $markup = '';
  if(!empty($urlqs_pk)) {
    // Escape primary key URL parameter before passing to database query
    $q_pk = $urlqs_pk;
    if(!is_numeric($q_pk) && !is_bool($q_pk)) {
      $q_pk = '\'' . $db->real_escape_string($q_pk) . '\'';
    }
    // Query database for specific record
    $q = $db->query('select * from ' . $table . ' where ' . $col_pk . ' = ' . $q_pk);
    $r = $q->fetch_array(MYSQLI_ASSOC);
    // Markup definition list of the record's column name and value pairs
    $markup .= '<dl>';
    if($q->num_rows) {
      foreach($r as $rKey => $rValue) {
        $markup .= '<dt>' . $rKey . '</dt><dd>' . (is_null($rValue) ? '<span class="null">(null)</span>' : ($rValue === '' ? '<span class="empty">(empty)</span>' : htmlentities($rValue))) . '</dd>';
      }
    }
    else {
      $markup .= '<dt class="error">Unable to retrieve the details of this record.</dt>';
    }
    $markup .= '</dl>';
    // Finish with query object
    $q->close();
  }
  // Return generated HTML markup
  return $markup;
}

// Begin HTML template below
?>

<html>
  <head>
    <title><?php echo $page_title; ?></title>
    <style>
      body {
        background-color: #fff;
        color: #000;
        margin: 1em;
      }
      body, table {
        font-family: sans-serif;
        font-size: 16px;
        line-height: 1.25em;
      }
      table {
        width: 100%;
        border-collapse: collapse;
      }
      thead {
        background-color: #abf;
        font-weight: bold;
      }
      thead th {
        padding: 0.25em 0.5em;
      }
      tbody tr:hover {
        background-color: #def;
      }
      tbody tr.active td {
        background-color: #eee !important;
      }
      tbody td {
        padding: 0.25em 0.5em;
        border-bottom: 1px solid #eee;
        vertical-align: top;
      }
      tbody td.primary_key {
        font-weight: bold;
      }
      dl {
        border-top: 1px solid #fff;
      }
      dt {
        font-weight: bold;
        margin: 0.5em 0 0 0;
        color: #555;
        font-size: 0.875em;
      }
      dd {
        margin: 0.25em 0 0.5em 2em;
      }
      dd span.null,
      dd span.empty {
        color: #999;
        font-style: italic;
      }
      .error {
        color: #a00;
        font-style: italic;
      }
      .error:before {
        content: "Error: ";
      }
      a {
        color: #00d;
      }
      a:visited {
        color: #337;
      }
      p.top_link {
        font-size: 0.875em;
        position: fixed;
        bottom: 0;
        right: 1rem;
        width: auto;
        background-color: #fff;
        opacity: 0.75;
        padding: 0.25em 0.5em;
        border-radius: 0.25em;
      }
    </style>
  </head>
  <body>
    <a name="#top"></a>
    <?php
    echo '<h1>' . $page_title . '</h1>';
    // Print the utility interface to the page
    echo markupRowList($db, $MySQL['list_sql'], $MySQL['table'], $MySQL['pk'], $urlqs_pk);
    // Additional navigation
    echo '<p class="top_link"><a href="#top">Top of page</a>';
    if(!empty($urlqs_pk)) {
      echo ' / <a href="#' . $urlqs_pk . '">Top of record</a>';
      echo ' / <a href="?pk=">Reset</a>';
    }
    echo '</p>';
    ?>
  </body>
</html>

<?php
// End HTML template above

// Finish with database object
$db->close();
?>