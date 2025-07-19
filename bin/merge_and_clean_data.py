import json
import sys

def merge_and_clean_data(course_data_file, manual_data_file, output_file):
    # Load data
    try:
        with open(course_data_file, 'r', encoding='utf-8') as f:
            course_data = json.load(f)
    except FileNotFoundError:
        course_data = []
        print(f"Warning: {course_data_file} not found, using empty course data")
    
    try:
        with open(manual_data_file, 'r', encoding='utf-8') as f:
            manual_data = json.load(f)
    except FileNotFoundError:
        manual_data = []
        print(f"Warning: {manual_data_file} not found, using empty manual data")
    
    # Combine data
    combined_data = course_data + manual_data
    
    # Clean data (remove duplicates, ensure required fields)
    cleaned_data = []
    seen_questions = set()
    mcq_count = 0
    numeric_count = 0
    
    for item in combined_data:
        # Ensure required fields exist, allow empty question for course content
        if not all(key in item for key in ['course_title', 'part_title', 'content', 'question_type', 'question', 'options', 'correct_answer']):
            continue
        # Only include items with non-empty questions for training
        if item['question'] and item['question'] not in seen_questions:
            seen_questions.add(item['question'])
            cleaned_data.append(item)
            if item['question_type'] == 'MCQ':
                mcq_count += 1
            elif item['question_type'] == 'Numeric':
                numeric_count += 1
    
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(cleaned_data, f, indent=2, ensure_ascii=False)
    
    print(f"MCQ: {mcq_count}, Numeric: {numeric_count}")
    print(f"Cleaned data saved to {output_file}")

if __name__ == "__main__":
    merge_and_clean_data(
        'C:\\Users\\GIGABYTE\\e_learnings\\bin\\course_data.json',
        'C:\\Users\\GIGABYTE\\e_learnings\\bin\\manual_data.json',
        'C:\\Users\\GIGABYTE\\e_learnings\\bin\\cleaned_training_data.json'
    )