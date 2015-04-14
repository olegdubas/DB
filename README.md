# DB
Useful database class based on PDO MySQL connection

## Usage
- This class is always used statically. This way the database connection is available everywhere in the code.

- This class can handle multiple database connections. Each database connection gets it's own identifier. When you open a connection, you can specify the ID, and you will have to use it every time you want to run a query on that connection. The default database connection ID is '**default**'

## Quick examples

The DB class is capable of much, much more than this, but here are a few examples of some easy routine tasks:

```
DB::open('localhost', 'root', '12345', 'database');

DB::query('UPDATE `users` SET `last_login` = NOW()');
DB::query('DELETE FROM `user_login_attempts`');

echo 'Age: ', DB::result("SELECT `age` FROM `users` WHERE `name` = 'Alex'"); 

$users = DB::objects('SELECT `name` FROM `users`');
foreach($users as $user) echo $user->name , '<br>'; 

```

## Connection manager

- `DB::open()` - open a MySQL connection immediately
- `DB::create()` - just another alias of `DB::open()`
- `DB::predict()` - predict a MySQL connection. It won't be open if not ever used, and it will open it on the first use.
- `DB::is_open()` - see, if a conection is currently open
- `DB::is_predicted()` - see, if a connection is predicted (no matter, is it open now or not)
- `DB::close()` - close a MySQL connection

### DB::open()

First, let's assume you ust just one database connection in your application. Then all you need is to create a default database connection with:

`DB::open('db_host', 'db_username', 'db_password', 'db_basename');`

The connection is now open and you can start using it.
If you want/have to have several connections, you can specify their IDs/names, like this:

`DB::open('second_database', 'db_host', 'db_username', 'db_password', 'db_basename');`

Then you'll be able to use `'second_database'` as an ID to run queries on this second connection. By default, if you don't specify a connection ID/name, the `'default'` will be used.

It means, the first example is totally identical to the following:

`DB::open('default', 'db_host', 'db_username', 'db_password', 'db_basename');`

You can also specify `:unbuffered` flag to a connection name, and it will be opened w/o buffering, which matches the following behavior: `$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);`:

Example:
```
DB::open(':unbuffered', 'db_host', 'db_username', 'db_password', 'db_basename'); // opens 'default', unbuffered
DB::open('second_database:unbuffered', 'db_host', 'db_username', 'db_password', 'db_basename');
```

### DB::predict()

`DB::predict()` is identical to `DB::open()`, except the fact that the connection isn't opened immediately, but it kept in "connections dictionary", and will be opened on the time of first usage. This helps to save resources, yet have all settings in one place.

For example, you are not sure if you'll be using the second database yet. Say, you have one single `index.php` which initializes everything. You can open the default database connection and predict the second one. Then, if some modules will try to query the second database, it will be immediately opened before the first request.

```
DB::open('default', 'db_host', 'db_username', 'db_password', 'db_basename');
DB::predict('second', 'db_host2', 'db_username2', 'db_password2', 'db_basename2');
// 'default' is open now. 'second' is not
DB::query("SELECT * FROM `users`");
// Still, 'default' is open now. 'second' is not
DB::query('second', "SELECT * FROM `books`");
// Now, 'second' is open too
```

## Query functions

Class DB has a set of query methods, all accepting SQL query as a parameter. No matter what type of result they return, all this methods have same parameters structure, explained below.

- `DB::query()` - main query function, returns statement object
- `DB::insert()` - analogue of `DB::query()`, but returns the last insert ID (similar to `PDO::lastInsertId`)
- `DB::result()` - returns one single value
- `DB::object()` - (alias: `DB::obj()`) returns one single row in form of an object
- `DB::assoc()` - returns one single row in form of an associative array
- `DB::row()` - returns one single row in form of a non-associative array
- `DB::all_objects()` - (aliases: `DB::all_object()`, `DB::all_obj()`) returns an array of records in form of objects
- `DB::all_assocs()` - (alias: `DB::all_assoc()`) returns an array of records in form of associative arrays
- `DB::all_rows()` - (alias: `DB::all_row()`) returns an array of records in form of non-associative arrays
- `DB::objects()` - returns an iterator object can be used in `foreach` loop over records as objects
- `DB::assocs()` - returns an iterator object can be used in `foreach` loop over records as associative arrays
- `DB::rows()` - returns an iterator object can be used in `foreach` loop over records as non-associative arrays

All this methods can be called with just one parameter — SQL query. Example:

```
DB::query("DELETE FROM `cars`");
```

All this methods can accept an additional parameter **before** the SQL query, specifying the database connection name:

```
echo DB::result('database2', "SELECT COUNT(*) FORM `cars`");
```
You can also add an optional `:unbuffered` flag to the connection name, and this particular query will be run in a separate, unbuffered connection, which matches the following behavior: `$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);`. It doesn't matter if the connection was open with the `:unbuffered` flag or not, specifying this flag in a query will open a separate connection, if needed.

Example:
```
DB::query('database2:unbuffered', "UPDATE `cars` SET `expired` = 0");
```

All this methods can accept an additional parameter **after** the SQL query, specifying the array of parameters binding. The class methods support two ways of parameters binding: using `?` and naming parameters specifically like `:name`:

```
DB::query("UPDATE `users` SET `name` = ? WHERE `id` = ?", [$name, $id] );
DB::query("UPDATE `users` SET `name` = :name WHERE `id` = :id", ['name' => $name, 'id' => $id] );
```

## Misc functions 
- `DB::escape()` - (alias: `DB::real_escape_string()`) escape characters (addslashes / mysql_real_escape_string analogue)
