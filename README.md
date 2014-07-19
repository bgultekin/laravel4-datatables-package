## Datatables Bundle for Laravel 4

**About**

This bundle is created to handle the server-side processing of the DataTables Jquery Plugin (http://datatables.net) by using Eloquent ORM or Fluent Query Builder.

### Feature Overview
- Supporting Eloquent ORM and Fluent Query Builder
- Adding or editing content of columns and removing columns
- Templating new or current columns via Blade Template Engine


### Installation

Add the `bllim/datatables` under the `require` key after that run the `composer update`.

    {
        "require": {
            "laravel/framework": "4.0.*",
            ...
            "bllim/datatables": "*"
        }
        ...
    }

Composer will download the package. After the package is downloaded, open "app/config/app.php" and add the service provider and alias as below:

    'providers' => array(
        ...
        'Bllim\Datatables\DatatablesServiceProvider',
    ),



    'aliases' => array(
        ...
        'Datatables'      => 'Bllim\Datatables\Datatables',
    ),

Finally you need to publish a configuration file by running the following Artisan command.

```php
$ php artisan config:publish bllim/datatables
```

### Usage

It is very simple to use this bundle. Just create your own fluent query object or eloquent object without getting results (that means don't use get(), all() or similar methods) and give it to Datatables.
You are free to use all Eloquent ORM and Fluent Query Builder features.

Some things you should know:
- When you call the select method on Eloquent or Fluent Query, you choose columns
- Modifying columns
    - You can easily edit columns by using `edit_column($column,$content)`
    - You can remove any column by the returned data by using `remove_column($column)`
    - You can add columns by using `add_column($column_name, $content, $order)`
    - You can use Blade Template Engine in your `$content` values
- The column identifiers are set by the returned array.
    - That means, for `posts.id` the relevant identifier is `id`, and for `owner.name as ownername` it is `ownername`
- You can set the "index" column (http://datatables.net/reference/api/row().index()) using set_index_column($name)
- You can opt for the response to include the data attribute for datatables 1.10+ by calling make(true) instead of make() (see Example 3)


### Examples

**Example 1:**

    $posts = Post::select(array('posts.id','posts.name','posts.created_at','posts.status'));

    return Datatables::of($posts)->make();


**Example 2:**

    $place = Place::left_join('owner','places.author_id','=','owner.id')
                    ->select(array('places.id','places.name','places.created_at','owner.name as ownername','places.status'));


    return Datatables::of($place)
    ->add_column('operations','<a href="{{ URL::route( \'admin.post\', array( \'edit\',$id )) }}">edit</a>
                    <a href="{{ URL::route( \'admin.post\', array( \'delete\',$id )) }}">delete</a>
                ')
    ->edit_column('status','@if($status)
                                Active
                            @else
                                Passive
                            @endif')
    ->edit_column('ownername', function($row) {
        // You can also pass a function for the $content argument
        // of the add_column or edit_column methods.
        // The query row/model record is passed as an argument to the function
        return "The author of this post is {$row->ownername}."
    })
    ->remove_column('id')
    ->make();

**Example 3:**

    $comments = Comment::select('comments.id', 'comments.user_id', 'comments.content');
    
    // Return the array of objects format supported by datatables 1.10+
    // Reference: https://datatables.net/manual/server-side
    return Datatables::of($comments)
        ->make(true);

**Notice:** If you use double quotes in the $content of `add_column` or `edit_column`, you should escape variables with a backslash (\\) to prevent an error. For example:

    edit_column('id',"- {{ \$id }}") .


**License:** Licensed under the MIT License
