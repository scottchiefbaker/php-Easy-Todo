DROP TABLE Todo;
CREATE TABLE Todo (
	TodoID INTEGER PRIMARY KEY,
	TodoGroupID INTEGER DEFAULT 1,
	TodoDateTimeAdded DATETIME,
	-- TodoDateTimeDue DATETIME,
	TodoPriority INTEGER,
	-- CategoryID INTEGER,
	TodoDesc VarChar(255),
	PersonID INTEGER,
	TodoCompletePercent INTEGER DEFAULT 0,
	TodoLastUpdate DATETIME
);

DROP TABLE Person;
CREATE TABLE Person (
	PersonID INTEGER PRIMARY KEY,
	PersonUniqID INTEGER,
	PersonName VarChar(40),
	PersonEmailAddress VarChar(150)
);

DROP TABLE Notes;
CREATE TABLE Notes (
	NoteID INTEGER PRIMARY KEY,
	TodoID INTEGER,
	NoteDateTime DATETIME,
	NoteText TEXT,
	PersonID INTEGER
);

/*
DROP TABLE Category;
CREATE TABLE Category (
	CategoryID INTEGER PRIMARY KEY,
	CategoryDesc VarChar(50)
);
*/
