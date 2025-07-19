# bin/merge_and_clean_data.py
import json

def merge_and_clean_data(input_files, output_file):
    all_data = []
    for file in input_files:
        with open(file, "r") as f:
            all_data.extend(json.load(f))

    cleaned_data = []
    for item in all_data:
        if not item.get("content") or not item.get("course_title") or not item.get("part_title"):
            continue
        if item.get("question_type") == "MCQ":
            if not item.get("options") or len(item["options"]) != 4 or item.get("correct_answer") not in item["options"]:
                continue
        if item.get("question_type") == "Numeric" and item.get("options"):
            continue
        cleaned_data.append({
            "course_title": item["course_title"],
            "part_title": item["part_title"],
            "content": item["content"],
            "question_type": item.get("question_type", ""),
            "question": item.get("question", ""),
            "options": item.get("options", []),
            "correct_answer": item.get("correct_answer", "")
        })

    mcq_count = sum(1 for item in cleaned_data if item["question_type"] == "MCQ")
    numeric_count = sum(1 for item in cleaned_data if item["question_type"] == "Numeric")
    print(f"MCQ: {mcq_count}, Numeric: {numeric_count}")

    with open(output_file, "w") as f:
        json.dump(cleaned_data, f, indent=2)
    print(f"Cleaned data saved to {output_file}")

merge_and_clean_data(["course_data.json", "training_data.json", "manual_data.json"], "cleaned_training_data.json")