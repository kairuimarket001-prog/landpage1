/*
  # Add RLS policies for user tracking tables

  ## Overview
  This migration adds Row Level Security (RLS) policies for user behavior tracking tables.
  
  ## Changes
  
  1. Security Policies
    - Add policies for `user_behaviors` table
    - Add policies for `page_tracking` table
    - Add policies for `customer_service_assignments` table
    - All tables are accessible only through service role for admin access
  
  ## Important Notes
  - RLS is already enabled on all tables
  - These policies ensure data can only be accessed through the backend API
  - No public read access is granted for security reasons
*/

-- User behaviors policies
DO $$
BEGIN
  -- Allow service role to insert
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies 
    WHERE tablename = 'user_behaviors' 
    AND policyname = 'Service role can insert user behaviors'
  ) THEN
    CREATE POLICY "Service role can insert user behaviors"
      ON user_behaviors
      FOR INSERT
      TO service_role
      WITH CHECK (true);
  END IF;

  -- Allow service role to select
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies 
    WHERE tablename = 'user_behaviors' 
    AND policyname = 'Service role can select user behaviors'
  ) THEN
    CREATE POLICY "Service role can select user behaviors"
      ON user_behaviors
      FOR SELECT
      TO service_role
      USING (true);
  END IF;
END $$;

-- Page tracking policies
DO $$
BEGIN
  -- Allow service role to insert
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies 
    WHERE tablename = 'page_tracking' 
    AND policyname = 'Service role can insert page tracking'
  ) THEN
    CREATE POLICY "Service role can insert page tracking"
      ON page_tracking
      FOR INSERT
      TO service_role
      WITH CHECK (true);
  END IF;

  -- Allow service role to select
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies 
    WHERE tablename = 'page_tracking' 
    AND policyname = 'Service role can select page tracking'
  ) THEN
    CREATE POLICY "Service role can select page tracking"
      ON page_tracking
      FOR SELECT
      TO service_role
      USING (true);
  END IF;
END $$;

-- Customer service assignments policies
DO $$
BEGIN
  -- Allow service role to insert
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies 
    WHERE tablename = 'customer_service_assignments' 
    AND policyname = 'Service role can insert assignments'
  ) THEN
    CREATE POLICY "Service role can insert assignments"
      ON customer_service_assignments
      FOR INSERT
      TO service_role
      WITH CHECK (true);
  END IF;

  -- Allow service role to select
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies 
    WHERE tablename = 'customer_service_assignments' 
    AND policyname = 'Service role can select assignments'
  ) THEN
    CREATE POLICY "Service role can select assignments"
      ON customer_service_assignments
      FOR SELECT
      TO service_role
      USING (true);
  END IF;

  -- Allow service role to update
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies 
    WHERE tablename = 'customer_service_assignments' 
    AND policyname = 'Service role can update assignments'
  ) THEN
    CREATE POLICY "Service role can update assignments"
      ON customer_service_assignments
      FOR UPDATE
      TO service_role
      USING (true)
      WITH CHECK (true);
  END IF;
END $$;

-- Create index for better query performance
CREATE INDEX IF NOT EXISTS idx_user_behaviors_session_id ON user_behaviors(session_id);
CREATE INDEX IF NOT EXISTS idx_user_behaviors_created_at ON user_behaviors(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_page_tracking_created_at ON page_tracking(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_customer_service_assignments_session_id ON customer_service_assignments(session_id);
