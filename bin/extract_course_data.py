import json
import os
from sqlalchemy import create_engine, text
from dotenv import load_dotenv

load_dotenv("C:/Users/GIGABYTE/e_learnings/.env.local")
db_url = os.getenv('PYTHON_DATABASE_URL')
if not db_url:
    raise ValueError("PYTHON_DATABASE_URL not found in .env.local")
engine = create_engine(db_url)

with engine.connect() as conn:
    # Fetch courses
    courses = conn.execute(text("SELECT id, title FROM course")).fetchall()
    data = []
    
    for course in courses:
        # Fetch parts for the course
        parts = conn.execute(
            text("SELECT id, title, description FROM part WHERE courseId = :course_id"),
            {"course_id": course.id}
        ).fetchall()
        
        for part in parts:
            # Fetch written section content
            written_section = conn.execute(
                text("SELECT content FROM writtensection WHERE partId = :part_id"),
                {"part_id": part.id}
            ).fetchone()
            
            # Fetch questions for the part (via quiz)
            quizzes = conn.execute(
                text("SELECT id FROM quiz WHERE partId = :part_id"),
                {"part_id": part.id}
            ).fetchall()
            
            questions = []
            for quiz in quizzes:
                quiz_questions = conn.execute(
                    text("SELECT text, type, options, correctAnswer FROM question WHERE quizId = :quiz_id"),
                    {"quiz_id": quiz.id}
                ).fetchall()
                questions.extend(quiz_questions)
            
            # Combine content
            content = (part.description or "") + "\n"
            if written_section and written_section.content:
                content += written_section.content + "\n"
                
            for question in questions:
                data.append({
                    "course_title": course.title,
                    "part_title": part.title,
                    "content": content.strip(),
                    "question_type": question.type,
                    "question": question.text,
                    "options": json.loads(question.options) if question.options else [],
                    "correct_answer": question.correctAnswer
                })
                
            # Add content without questions for parts that don't have quizzes
            if not questions:
                data.append({
                    "course_title": course.title,
                    "part_title": part.title,
                    "content": content.strip(),
                    "question_type": "",
                    "question": "",
                    "options": [],
                    "correct_answer": ""
                })
    
    with open("course_data.json", "w") as f:
        json.dump(data, f, indent=2)
print("Course data extracted to course_data.json")