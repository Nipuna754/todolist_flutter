import 'package:flutter/foundation.dart';

class TodoItem {
  final int id ;
  final String title;
  final String description;
  bool isCompleted;

  TodoItem({

    required this.id,required this.title,required this.description,required this.isCompleted
  });

  void toggleCompletion() {
    isCompleted = !isCompleted;
  }

  factory TodoItem.fromMap(Map<String, dynamic> map) {
    return TodoItem(
      id: map['id'],
      title: map['title'],
      description: map['description'],
      isCompleted: map['isCompleted'] == 1,
    );
  }

  Map<String, dynamic> toMap() {
    return {
      'id': id,
      'title': title,
      'description': description,
      'isCompleted': isCompleted ? 1 : 0,
    };
  }

  @override
  String toString() {
    return 'TodoItem{id: $id, title: $title, description: $description, isCompleted: $isCompleted}';
  }
}
