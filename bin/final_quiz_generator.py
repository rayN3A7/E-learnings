import json
import sys
import os
from sqlalchemy import create_engine, text
from dotenv import load_dotenv
from transformers import T5Tokenizer, T5ForConditionalGeneration

load_dotenv()
db_url = os.getenv('PYTHON_DATABASE_URL')
if not db_url:
    raise ValueError("PYTHON_DATABASE_URL not found in .env.local")
engine = create_engine(db_url)

tokenizer = T5Tokenizer.from_pretrained('./quiz_generator_model')
model = T5ForConditionalGeneration.from_pretrained('./quiz_generator_model')

input_data = json.loads(sys.stdin.read())
course_id = input_data['course_id']
user_id = input_data['user_id']

# Fetch user performance
with engine.connect() as conn:
    # Get parts for the course
    parts = conn.execute(
        text("SELECT id, title, description FROM part WHERE courseId = :course_id"),
        {"course_id": course_id}
    ).fetchall()
    
    # Get quiz attempts
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
    
    # If no weak parts, include all parts
    if not weak_parts:
        weak_parts = parts
    
    # Fetch written section content for weak parts
    questions = []
    for part in weak_parts:
        written_section = conn.execute(
            text("SELECT content FROM writtensection WHERE partId = :part_id"),
            {"part_id": part.id}
        ).fetchone()
        content = (part.description or "") + "\n" + (written_section.content if written_section else "")
        
        for q_type in ['MCQ', 'Numeric']:
            prompt = f"Course: {input_data['course_title']}\nPart: {part.title}\nContent: {content}\nGenerate a {q_type} question."
            inputs = tokenizer(prompt, return_tensors='pt', max_length=512, truncation=True, padding=True)
            outputs = model.generate(**inputs, max_length=256, num_return_sequences=1)
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
    
    # Create final quiz
    quiz_result = conn.execute(
        text("INSERT INTO quiz (partId, title, generatedByAI, createdAt, scoreWeight) VALUES (NULL, :title, :generatedByAI, NOW(), :scoreWeight) RETURNING id"),
        {
            "title": f"Final Quiz for Course: {input_data['course_title']}",
            "generatedByAI": True,
            "scoreWeight": 1.0
        }
    )
    quiz_id = quiz_result.fetchone()[0]
    
    # Insert questions
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