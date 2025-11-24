-- Migration: fix steps primary key to allow multiple turn records per player
-- Date: 2025-11-18
-- Safe to run once. Idempotency checks included.

BEGIN;

-- 1. Add surrogate key column if not exists
ALTER TABLE steps ADD COLUMN IF NOT EXISTS id_step BIGSERIAL;

-- 2. Drop old primary key if it is only on id_player
DO $$
DECLARE
    pk_cols text;
BEGIN
    SELECT string_agg(a.attname, ',') INTO pk_cols
    FROM pg_index i
    JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
    WHERE i.indrelid = 'steps'::regclass AND i.indisprimary;
    IF pk_cols = 'id_player' THEN
        ALTER TABLE steps DROP CONSTRAINT (SELECT conname FROM pg_constraint WHERE conrelid = 'steps'::regclass AND contype = 'p');
    END IF;
END $$;

-- 3. Ensure new primary key on id_step
ALTER TABLE steps ADD CONSTRAINT steps_pkey PRIMARY KEY USING INDEX (
    SELECT indexrelid::regclass FROM pg_index WHERE indrelid='steps'::regclass AND indisprimary
); -- This line will fail if there is already a PK; fallback below

-- Fallback if previous statement failed (already primary key different)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conrelid='steps'::regclass AND contype='p' AND conname='steps_pkey'
    ) THEN
        ALTER TABLE steps ADD CONSTRAINT steps_pkey PRIMARY KEY (id_step);
    END IF;
END $$;

-- 4. Helpful index for latest turn lookups
CREATE INDEX IF NOT EXISTS idx_steps_game_player_time ON steps (id_player, step_begin DESC);

COMMIT;

-- Verification Query (optional):
-- SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='steps'::regclass AND contype='p';
