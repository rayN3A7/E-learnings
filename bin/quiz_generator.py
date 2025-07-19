import json
import sys
import os
from sqlalchemy import create_engine, text
from dotenv import load_dotenv
from transformers import T5Tokenizer, T5ForConditionalGeneration

# Use absolute path for .env.local
env_path = r"C:\Users\GIGABYTE\e_learnings\.env.local"
load_dotenv(env_path)
db_url = os.getenv('PYTHON_DATABASE_URL')
if not db_url:
    raise ValueError("PYTHON_DATABASE_URL not found in .env.local")
engine = create_engine(db_url)

# Use absolute path for the local model with local_files_only
model_path = r"C:\Users\GIGABYTE\e_learnings\bin\quiz_generator_model"
tokenizer = T5Tokenizer.from_pretrained(model_path, local_files_only=True)
model = T5ForConditionalGeneration.from_pretrained(model_path, local_files_only=True)

# Part quiz generation
input_data = json.loads(sys.stdin.read())
course_title = input_data['course_title']
part_title = input_data['part_title']
content = input_data['content']
part_id = input_data['part_id']

questions = []
for q_type in ['MCQ', 'Numeric']:
    prompt = f"Course: {course_title}\nPart: {part_title}\nContent: {content}\nGenerate a {q_type} question."
    inputs = tokenizer(prompt, return_tensors='pt', max_length=512, truncation=True, padding=True)
    outputs = model.generate(**inputs, max_length=100, num_return_sequences=1, no_repeat_ngram_size=2)
    generated = tokenizer.decode(outputs[0], skip_special_tokens=True)
    
    lines = generated.split('\n')
    question_data = {
        "type": q_type,
        "text": lines[0].replace('Question: ', '') if lines else f"Default {q_type} question for {part_title}",
        "options": json.loads(lines[1].replace('Options: ', '')) if q_type == 'MCQ' and len(lines) > 1 else [],
        "correctAnswer": lines[2].replace('Answer: ', '') if len(lines) > 2 else "Default answer"
    }
    if q_type == 'MCQ' and not question_data['options']:
        question_data['options'] = ["Option 1", "Option 2", "Option 3", "Option 4"]
        question_data['correctAnswer'] = question_data['options'][0]
    questions.append(question_data)

with engine.connect() as conn:
    conn.execute(
        text("INSERT INTO quiz (partId, title, generatedByAI, createdAt, scoreWeight) VALUES (:part_id, :title, :generatedByAI, NOW(), :scoreWeight)"),
        {
            "part_id": part_id,
            "title": f"Quiz for Part: {part_title} (Course: {course_title})",
            "generatedByAI": True,
            "scoreWeight": 1.0
        }
    )
    quiz_id = conn.execute(text("SELECT LAST_INSERT_ID()")).scalar()
    
    for q in questions:
        conn.execute(
            text("INSERT INTO question (quizId, text, type, options, correctAnswer, generatedByAI) VALUES (:quiz_id, :text, :type, :options, :correctAnswer, :generatedByAI)"),
            {
                "quiz_id": quiz_id,
                "text": q["text"],
                "type": q["type"],
                "options": json.dumps(q["options"]) if q["options"] else None,
                "correctAnswer": q["correctAnswer"],
                "generatedByAI": True
            }
        )
    conn.commit()

print(json.dumps({"questions": questions}))

# Final quiz generation (using the same input_data)
course_id = input_data.get('course_id')
user_id = input_data.get('user_id')
course_title = input_data.get('course_title', course_title)  # Fallback to part quiz title
if not course_id or not user_id:
    raise ValueError("course_id and user_id are required for final quiz generation")

with engine.connect() as conn:
    parts = conn.execute(
        text("SELECT id, title, description FROM part WHERE courseId = :course_id"),
        {"course_id": course_id}
    ).fetchall()
    
    weak_parts = []
    for part in parts:
        quiz = conn.execute(
            text("SELECT id FROM quiz WHERE partId = :part_id"),
            {"part_id": part.id}
        ).fetchone()
        if quiz:
            attempt = conn.execute(
                text("SELECT score FROM quizattempt WHERE quizId = :quiz_id AND userId = :user_id ORDER BY takenAt DESC LIMIT 1"),
                {"quiz_id": quiz.id, "user_id": user_id}
            ).fetchone()
            if attempt and attempt.score < 70:
                weak_parts.append(part)
    
    if not weak_parts:
        weak_parts = parts
    
    questions = []
    for part in weak_parts:
        written_section = conn.execute(
            text("SELECT content FROM writtensection WHERE partId = :part_id"),
            {"part_id": part.id}
        ).fetchone()
        content = (part.description or "") + "\n" + (written_section.content if written_section else "")
        
        for q_type in ['MCQ', 'Numeric']:
            prompt = f"Course: {course_title}\nPart: {part.title}\nContent: {content}\nGenerate a {q_type} question."
            inputs = tokenizer(prompt, return_tensors='pt', max_length=512, truncation=True, padding=True)
            outputs = model.generate(**inputs, max_length=100, num_return_sequences=1, no_repeat_ngram_size=2)
            generated = tokenizer.decode(outputs[0], skip_special_tokens=True)
            
            lines = generated.split('\n')
            question_data = {
                "type": q_type,
                "text": lines[0].replace('Question: ', '') if lines else f"Default {q_type} question for {part.title}",
                "options": json.loads(lines[1].replace('Options: ', '')) if q_type == 'MCQ' and len(lines) > 1 else [],
                "correctAnswer": lines[2].replace('Answer: ', '') if len(lines) > 2 else "Default answer"
            }
            if q_type == 'MCQ' and not question_data['options']:
                question_data['options'] = ["Option 1", "Option 2", "Option 3", "Option 4"]
                question_data['correctAnswer'] = question_data['options'][0]
            questions.append(question_data)
    
    conn.execute(
        text("INSERT INTO quiz (partId, title, generatedByAI, createdAt, scoreWeight) VALUES (NULL, :title, :generatedByAI, NOW(), :scoreWeight)"),
        {
            "title": f"Final Quiz for Course: {course_title}",
            "generatedByAI": True,
            "scoreWeight": 1.0
        }
    )
    quiz_id = conn.execute(text("SELECT LAST_INSERT_ID()")).scalar()
    
    for q in questions:
        conn.execute(
            text("INSERT INTO question (quizId, text, type, options, correctAnswer, generatedByAI) VALUES (:quiz_id, :text, :type, :options, :correctAnswer, :generatedByAI)"),
            {
                "quiz_id": quiz_id,
                "text": q["text"],
                "type": q["type"],
                "options": json.dumps(q["options"]) if q["options"] else None,
                "correctAnswer": q["correctAnswer"],
                "generatedByAI": True
            }
        )
    conn.commit()

print(json.dumps({"quiz_id": quiz_id, "questions": questions}))