-- OPTIONAL: para lang sa DA nga mark. (Return kag Transfer awtomatiko na.)
ALTER TABLE distributions
  ADD COLUMN source_type ENUM('return','transfer','da') NULL DEFAULT NULL AFTER status;
-- Para i-mark ang sapat nga halin sa DA:
-- UPDATE distributions SET source_type='da' WHERE distribution_id IN (..);