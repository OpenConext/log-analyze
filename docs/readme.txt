Welcome to the log analyzer of OpenConext logins.


-------
PURPOSE
-------
The purpose of this analyzing program is to convert the logfiles from OpenConext, one big mysql table, to a more suitable form, that can be used as input for statistics.

There are three main directories
1. db, with the database scripts to setup mysql
2. docs, with the explanation of the program
3. scripts, with the actual program.


---------
MORE INFO
---------
* For installation of the log analyzer, please read docs/install.txt
* To understand the log analyzer and start playing with the configuration, please read docs/play.txt
* For background information on the log analyzer, please read docs/details.txt


-----------
QUICK START
-----------
Make sure you installed everything using docs/install.txt, the main purpose is to get your database setup.

Now, go to the directory where the scripts are located.

command:
cd scripts/bin

There are three scripts.

1. First run the chunk.php script to create equal sized chunks of the logins table

command:
./chunk.php --from="2013-11-26 00:00:00" --to="2013-11-28 23:59:59"

With this command all log entries from 2013-11-26 to 2013-11-28 will be processed

2. Second run the actual analyzer

command:
./analyze.php

With this command all chunk created in the first step are converted in the new database structure

3. Test your run

command:
./test.php from=2013-11-26 to=2013-11-28

With this command the main counters and some random loglines are tested for completeness.


That's it. Have fun!

Herman.
(herman@dompseler.nl)
