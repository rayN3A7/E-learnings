from datasets import load_dataset
import json

# Load GSM8K dataset
dataset = load_dataset("gsm8k", "main")["train"]
training_data = []

# Synthetic content to simulate course material
base_content = "Numerical analysis involves solving equations like f(x) = 0 using methods such as bisection or Newton-Raphson."

# Filter for equation-solving questions
for item in dataset:
    if len(training_data) >= 100:  # Limit to 100 questions
        break
    question_text = item["question"].lower()
    if "equation" in question_text or "solve" in question_text:
        training_data.append({
            "content": base_content,
            "question_type": "Numeric",
            "question": item["question"],
            "options": [],
            "correct_answer": item["answer"].split("####")[-1].strip()
        })

# Add a sample MCQ to balance dataset
training_data.append({
    "content": "The bisection method finds roots by repeatedly bisecting an interval and selecting the subinterval where the function changes sign.",
    "question_type": "MCQ",
    "question": "What is the purpose of the bisection method in numerical analysis?",
    "options": ["To find roots of a function", "To interpolate data points", "To solve differential equations", "To optimize functions"],
    "correct_answer": "To find roots of a function"
})

with open("training_data.json", "w") as f:
    json.dump(training_data, f, indent=2)
print("GSM8K data extracted to training_data.json")