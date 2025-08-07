content = '''DATABASE_URL="mysql://root:@127.0.0.1:3306/e_genius?serverVersion=8.0.32&charset=utf8mb4"
PYTHON_DATABASE_URL="mysql+pymysql://root:@127.0.0.1:3306/e_genius"
PYTHON_BINARY=C:/Users/GIGABYTE/e_learnings/venv/Scripts/python.exe
QUIZ_GENERATOR_SCRIPT=C:/Users/GIGABYTE/e_learnings/bin/quiz_generator.py
FINAL_QUIZ_GENERATOR_SCRIPT=C:/Users/GIGABYTE/e_learnings/bin/final_quiz_generator.py
'''

with open(".env.local", "w", encoding="utf-8") as f:
    f.write(content)
