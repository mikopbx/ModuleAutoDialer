﻿
Процедура ПередЗаписью(Отказ)
// Формируем структуру данных для отправки.
	Опрос = Новый Структура();
	Опрос.Вставить("crmId", Код);
	Опрос.Вставить("name",  Наименование);   
	Опрос.Вставить("questions", Новый Массив);   
	
	Для каждого Вопрос Из Вопросы Цикл
		Ответы = ВариантыОтветов.НайтиСтроки(Новый Структура("questionId", Вопрос.questionId)); 
		Если Ответы.Количество() = 0 Тогда
			Отказ = Истина;
			Сообщить("Для вопроса " + Вопрос.questionId + " не описаны варианты ответов.");
		КонецЕсли;	
		question = Новый Структура();    
		question.Вставить("questionId", 	Вопрос.questionId);
		question.Вставить("questionText", 	Вопрос.questionText);            
		Если ЗначениеЗаполнено(Вопрос.lang) Тогда
			question.Вставить("lang", 			Вопрос.lang);   
		КонецЕсли;
		question.Вставить("press", Новый Массив);
		Для каждого Ответ Из Ответы Цикл
			question.press.Добавить(Новый Структура("key,action,value,nextQuestion", Ответ.key, Ответ.action, Ответ.value, Ответ.nextQuestion));
		КонецЦикла;
		Опрос.questions.Добавить(question);  
	
	КонецЦикла;
	
	Результат = МИКО_аиAPI.СоздатьОпрос(Опрос);
	Отказ 		= (Результат.result = Ложь);
	Если Отказ Тогда
		Сообщить("Не вышло отправить задачу на сверер MikoPBX. Проверьте параметры подключения.");
	Иначе
		ИдентификаторОпроса = Результат.data.id;
	КонецЕсли;
КонецПроцедуры

