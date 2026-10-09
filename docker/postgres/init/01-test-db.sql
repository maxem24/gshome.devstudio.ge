-- Выполняется один раз, при создании тома postgres: отдельная база для тестов,
-- чтобы сьют (RefreshDatabase) никогда не трогал рабочую.
CREATE DATABASE gshome_test OWNER gshome;
