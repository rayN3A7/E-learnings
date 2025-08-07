import json
import torch
from transformers import T5Tokenizer, T5ForConditionalGeneration
from torch.utils.data import Dataset, DataLoader
from tqdm import tqdm

# Custom Dataset
class QuizDataset(Dataset):
    def __init__(self, data, tokenizer, max_length=512):
        self.data = data
        self.tokenizer = tokenizer
        self.max_length = max_length
        print(f"Dataset initialized with {len(self.data)} items")

    def __len__(self):
        return len(self.data)

    def __getitem__(self, idx):
        item = self.data[idx]
        input_text = f"Course: {item['course_title']}\nPart: {item['part_title']}\nContent: {item['content']}\nGenerate a {item['question_type']} question."
        output_text = f"Question: {item['question']}\nOptions: {json.dumps(item['options'])}\nAnswer: {item['correct_answer']}" if item['question_type'] == 'MCQ' else f"Question: {item['question']}\nAnswer: {item['correct_answer']}"
        
        input_encoding = self.tokenizer(input_text, max_length=self.max_length, padding='max_length', truncation=True, return_tensors='pt')
        output_encoding = self.tokenizer(output_text, max_length=self.max_length, padding='max_length', truncation=True, return_tensors='pt')
        
        return {
            'input_ids': input_encoding['input_ids'].squeeze(),
            'attention_mask': input_encoding['attention_mask'].squeeze(),
            'labels': output_encoding['input_ids'].squeeze()
        }

# Load data
with open('C:\\Users\\GIGABYTE\\e_learnings\\bin\\cleaned_training_data.json', 'r', encoding='utf-8') as f:
    data = json.load(f)
print(f"Raw data loaded: {len(data)} items")

# Filter out empty questions
data = [item for item in data if item['question'] and item['question_type']]
print(f"Filtered data: {len(data)} items after filtering")

# Initialize tokenizer and model
tokenizer = T5Tokenizer.from_pretrained('t5-small')
model = T5ForConditionalGeneration.from_pretrained('t5-small')

# Create dataset and dataloader
dataset = QuizDataset(data, tokenizer)
dataloader = DataLoader(dataset, batch_size=8, shuffle=True)

# Training setup
device = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
model.to(device)
optimizer = torch.optim.AdamW(model.parameters(), lr=5e-5)

# Training loop
model.train()
for epoch in range(3):  # Adjust epochs as needed
    total_loss = 0
    for batch in tqdm(dataloader, desc=f"Epoch {epoch + 1}"):
        input_ids = batch['input_ids'].to(device)
        attention_mask = batch['attention_mask'].to(device)
        labels = batch['labels'].to(device)
        
        outputs = model(input_ids=input_ids, attention_mask=attention_mask, labels=labels)
        loss = outputs.loss
        total_loss += loss.item()
        
        optimizer.zero_grad()
        loss.backward()
        optimizer.step()
    
    print(f"Epoch {epoch + 1}, Average Loss: {total_loss / len(dataloader)}")

# Save the model
model.save_pretrained('./quiz_generator_model')
tokenizer.save_pretrained('./quiz_generator_model')
print("Model and tokenizer saved to ./quiz_generator_model")