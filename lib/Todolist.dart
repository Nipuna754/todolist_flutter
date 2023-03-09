
import 'package:flutter/material.dart';
import 'package:new_app/screens/TodoItem_db.dart';


class TodoListScreen extends StatefulWidget {
  @override
  _TodoListScreenState createState() => _TodoListScreenState();
}

class _TodoListScreenState extends State<TodoListScreen> {
  final List<TodoItem> _todoList = [];

  void _addTodoItem(String title) {
    setState(() {
      _todoList.add(TodoItem(title: title, id: 0, description: '', isCompleted:true));
    });
  }

  void _removeTodoItem(int index) {
    setState(() {
      _todoList.removeAt(index);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Todo List'),
      ),
      body: ListView.builder(
        itemCount: _todoList.length,
        itemBuilder: (context, index) {
          return ListTile(
            leading: Icon(Icons.check_circle_outline),
            title: Text(_todoList[index].title),
            trailing: IconButton(
              icon: Icon(Icons.delete),
              onPressed: () {
                _removeTodoItem(index);
              },
            ),
          );
        },
      ),
      floatingActionButton: FloatingActionButton(
        child: Icon(Icons.add),
        onPressed: () async {
          final String? newTitle = await showDialog<String>(
            context: context,
            builder: (context) => AlertDialog(
              title: Text('New Todo Item'),
              content: TextField(
                autofocus: true,
                decoration: InputDecoration(
                  labelText: 'Todo Item',
                  hintText: 'Enter todo item',
                ),
              ),
              actions: [
                TextButton(
                  child: Text('Cancel'),
                  onPressed: () {
                    Navigator.of(context).pop();
                  },
                ),
                TextButton(
                  child: Text('Add'),
                  onPressed: () {
                    Navigator.of(context).pop();
                  },
                ),
              ],
            ),
          );

          if (newTitle != null) {
            _addTodoItem(newTitle);
          }
        },
      ),
    );
  }
}
