# test_db.py
from sqlalchemy import create_engine
from dotenv import load_dotenv
import os

load_dotenv()
engine = create_engine(os.getenv('DATABASE_URL'))
with engine.connect() as connection:
    print("Database connection successful!")