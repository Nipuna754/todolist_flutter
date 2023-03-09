import 'package:flutter/material.dart';
import 'package:new_app/screens/TodoItem_db.dart';

class TodoItemTile extends StatelessWidget {
  final TodoItem todoItem;
  final Function(TodoItem) toggleTodoItem;

  const TodoItemTile({
    Key? key,
    required this.todoItem,
    required this.toggleTodoItem,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return CheckboxListTile(
      value: todoItem.isCompleted,
      title: Text(todoItem.title),
      subtitle: Text(todoItem.description),
      onChanged: (bool? value) {
        toggleTodoItem(todoItem);
      },
    );
  }
}
