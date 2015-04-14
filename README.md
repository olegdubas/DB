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

$exists = DB::result("SELECT COUNT(*) FROM `users` WHERE `name` = 'Alex'");
echo $exists;

$users = DB::objects('SELECT `name` FROM `users`');
foreach($users as $user) echo $user->name; 

```

## Connection managing

- **DB::open()** - open a MySQL connection immediately
- **DB::create()** - just another alias of DB::open
- **DB::predict()** - predict a MySQL connection. It won't be open if not ever used, and it will open it on the first use.
- **DB::is_open()** - see, if a conection is currently open
- **DB::is_predicted()** - see, if a connection is predicted (no matter, is it open now or not)
- **DB::close()** - close a MySQL connection

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

DB::predict() is identical to DB::open(), 

## Misc functions 
- **DB::escape()** - escape characters (addslashes / mysql_real_escape_string analogue)
