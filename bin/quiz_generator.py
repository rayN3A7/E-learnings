import json
import sys
import os
import re
from sqlalchemy import create_engine, text
from dotenv import load_dotenv
from transformers import T5Tokenizer, T5ForConditionalGeneration

# Load environment variables
load_dotenv()
db_url = os.getenv('PYTHON_DATABASE_URL')
if not db_url:
    raise ValueError("PYTHON_DATABASE_URL not found in .env.local")
engine = create_engine(db_url)

# Load T5 model and tokenizer
model_path = "./quiz_generator_model"  # Adjust path as needed
tokenizer = T5Tokenizer.from_pretrained(model_path, local_files_only=True)
model = T5ForConditionalGeneration.from_pretrained(model_path, local_files_only=True)

# Read input data
input_data = json.loads(sys.stdin.read())

def clean_text(text):
    """Remove non-English characters and ensure clean text."""
    # Remove LaTeX formatting and special characters, keep basic math symbols
    text = re.sub(r'\\[a-zA-Z]+{[^}]+}', lambda m: m.group(0).replace('\\', ''), text)
    text = re.sub(r'[^\x00-\x7F]+', ' ', text)  # Remove non-ASCII
    return text.strip()

def validate_mcq_options(options, correct_answer):
    """Ensure MCQ options are valid and include the correct answer."""
    if not isinstance(options, list) or len(options) != 4:
        return ["Option A", "Option B", "Option C", "Option D"], "Option A"
    if correct_answer not in options:
        options[0] = correct_answer
    return options, correct_answer

def validate_numeric_answer(answer):
    """Ensure numeric answer is a valid float or integer."""
    try:
        return str(float(answer))
    except (ValueError, TypeError):
        return "0"

def generate_question(prompt, q_type, max_attempts=3):
    """Generate a single question with retries for quality."""
    for _ in range(max_attempts):
        inputs = tokenizer(prompt, return_tensors='pt', max_length=512, truncation=True, padding=True)
        outputs = model.generate(
            **inputs,
            max_length=256,
            num_return_sequences=1,
            no_repeat_ngram_size=2,
            do_sample=True,
            temperature=0.7  # Controlled randomness for better variety
        )
        generated = tokenizer.decode(outputs[0], skip_special_tokens=True)
        lines = [line.strip() for line in generated.split('\n') if line.strip()]

        if not lines:
            continue

        question_data = {"type": q_type}
        question_data["text"] = clean_text(lines[0].replace('Question: ', ''))

        if q_type == 'MCQ':
            try:
                options = json.loads(lines[1].replace('Options: ', '')) if len(lines) > 1 else []
                question_data["options"] = [clean_text(opt) for opt in options]
                question_data["correctAnswer"] = clean_text(lines[2].replace('Answer: ', '')) if len(lines) > 2 else options[0] if options else "Option A"
                question_data["options"], question_data["correctAnswer"] = validate_mcq_options(question_data["options"], question_data["correctAnswer"])
            except json.JSONDecodeError:
                question_data["options"] = ["Option A", "Option B", "Option C", "Option D"]
                question_data["correctAnswer"] = "Option A"
        else:  # Numeric
            question_data["correctAnswer"] = validate_numeric_answer(lines[1].replace('Answer: ', '')) if len(lines) > 1 else "0"
            question_data["options"] = None

        if question_data["text"] and not question_data["text"].startswith("Default"):
            return question_data

    # Fallback if generation fails
    return {
        "type": q_type,
        "text": f"Default {q_type} question for numerical analysis",
        "options": ["Option A", "Option B", "Option C", "Option D"] if q_type == 'MCQ' else None,
        "correctAnswer": "Option A" if q_type == 'MCQ' else "0"
    }

# Part quiz generation
if 'part_id' in input_data:
    course_title = clean_text(input_data['course_title'])
    part_title = clean_text(input_data['part_title'])
    content = clean_text(input_data['content'])
    part_id = input_data['part_id']

    questions = []
    for q_type in ['MCQ', 'Numeric']:
        for i in range(5):  # Generate 5 questions per type
            prompt = (
                f"Generate a {q_type} question in English for a numerical analysis course aimed at students weak in math. "
                f"The question must be clear, concise, and directly related to the following content:\n"
                f"Course: {course_title}\nPart: {part_title}\nContent: {content}\n"
                f"For MCQ, provide 4 distinct options with one correct answer. For Numeric, provide a precise numerical answer. "
                f"Ensure the question is educational, avoids complex jargon, and is appropriate for beginners."
            )
            question_data = generate_question(prompt, q_type)
            questions.append(question_data)

    with engine.connect() as conn:
        quiz_result = conn.execute(
            text("INSERT INTO quiz (partId, title, generatedByAI, createdAt, scoreWeight) VALUES (:part_id, :title, :generatedByAI, NOW(), :scoreWeight) RETURNING id"),
            {
                "part_id": part_id,
                "title": f"Quiz for Part: {part_title} (Course: {course_title})",
                "generatedByAI": True,
                "scoreWeight": 1.0
            }
        )
        quiz_id = quiz_result.fetchone()[0]

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

# Final quiz generation
elif 'course_id' in input_data and 'user_id' in input_data:
    course_id = input_data['course_id']
    user_id = input_data['user_id']
    course_title = clean_text(input_data['course_title'])

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
            part_title = clean_text(part.title)
            written_section = conn.execute(
                text("SELECT content FROM writtensection WHERE partId = :part_id"),
                {"part_id": part.id}
            ).fetchone()
            content = clean_text((part.description or "") + "\n" + (written_section.content if written_section else ""))

            for q_type in ['MCQ', 'Numeric']:
                prompt = (
                    f"Generate a {q_type} question in English for a numerical analysis course aimed at students weak in math. "
                    f"The question must be clear, concise, and directly related to the following content:\n"
                    f"Course: {course_title}\nPart: {part_title}\nContent: {content}\n"
                    f"For MCQ, provide 4 distinct options with one correct answer. For Numeric, provide a precise numerical answer. "
                    f"Ensure the question is educational, avoids complex jargon, and is appropriate for beginners."
                )
                question_data = generate_question(prompt, q_type)
                questions.append(question_data)

        quiz_result = conn.execute(
            text("INSERT INTO quiz (partId, title, generatedByAI, createdAt, scoreWeight) VALUES (NULL, :title, :generatedByAI, NOW(), :scoreWeight) RETURNING id"),
            {
                "title": f"Final Quiz for Course: {course_title}",
                "generatedByAI": True,
                "scoreWeight": 1.0
            }
        )
        quiz_id = quiz_result.fetchone()[0]

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

else:
    raise ValueError("Invalid input data: part_id is required for part quiz, or course_id and user_id are required for final quiz")